<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Transport;

use Basis\Nats\Client;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;
use Etrias\PhpToolkit\Counter\Counter;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\DeduplicateStamp;
use Etrias\PhpToolkit\Messenger\Stamp\ReplyToStamp;
use Monolog\Level;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

final class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    private const HEADER_MESSAGE_ID = 'Nats-Msg-Id';
    // @todo configurable
    private const ACK_WAIT_SECONDS = 300;
    // @todo configurable
    private const DEDUPLICATE_WINDOW_SECONDS = 10;
    // @todo configurable
    private const STREAM_REPLICAS = 1;

    private ?Stream $stream = null;
    private ?Consumer $consumer = null;
    private ?string $streamId = null;

    public function __construct(
        private readonly Client $client,
        private readonly SerializerInterface $serializer,
        private readonly MessageMap $messageMap,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger,
        private readonly NormalizerInterface $normalizer,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $streamName,
    ) {}

    public function setup(bool $refresh = false, bool $dryRun = false): void
    {
        $stream = $this->getStream();

        if ($stream->exists()) {
            if ($refresh) {
                if (!$dryRun) {
                    $stream->update();
                }

                $this->log(Level::Notice, $stream, 'Stream updated');
            } else {
                $this->log(Level::Info, $stream, 'Stream already exists');
            }
        } else {
            if (!$dryRun) {
                $stream->create();
            }

            $this->log(Level::Notice, $stream, 'Stream created');
        }

        $consumer = $this->getConsumer();

        if ($consumer->exists()) {
            if ($refresh) {
                if (!$dryRun) {
                    // create also updates consumer
                    $this->client->api('CONSUMER.DURABLE.CREATE.'.$stream->getName().'.'.$consumer->getName(), $consumer->getConfiguration()->toArray());
                }

                $this->log(Level::Notice, $consumer, 'Consumer updated');
            } else {
                $this->log(Level::Info, $consumer, 'Consumer already exists');
            }
        } else {
            if (!$dryRun) {
                $consumer->create();
            }

            $this->log(Level::Notice, $consumer, 'Consumer created');
        }
    }

    public function get(): array
    {
        $this->checkMessageCount();

        $receivedMessages = [];

        try {
            // @todo inline handle, batch lazy (yield) per 50 (config)
            $this->getConsumer()->handle(function (Payload $payload, ?string $replyTo) use (&$receivedMessages): void {
                $stamps = [new TransportMessageIdStamp($payload->getHeader(self::HEADER_MESSAGE_ID))];
                if (null !== $replyTo) {
                    $stamps[] = new ReplyToStamp($replyTo, new \DateTimeImmutable('+'.self::ACK_WAIT_SECONDS.' seconds'));
                }

                $receivedMessages[] = $this->serializer->decode(['body' => $payload->body])->with(...$stamps);
            }, null, false);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0);
        }

        return $receivedMessages;
    }

    public function ack(Envelope $envelope): void
    {
        if (null === $replyTo = $envelope->last(ReplyToStamp::class)) {
            return;
        }

        if (new \DateTimeImmutable() >= $replyTo->expiresAt) {
            $this->log(Level::Warning, $envelope, 'Message "{message}" expired', [
                'expired_at' => $replyTo->expiresAt,
            ]);
        }

        try {
            $this->client->publish($replyTo->id, true);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        $this->delta($envelope, true);
    }

    /**
     * @see https://github.com/symfony/symfony/issues/52642
     */
    public function reject(Envelope $envelope): void {}

    public function send(Envelope $envelope): Envelope
    {
        $envelope = $envelope->withoutAll(TransportMessageIdStamp::class);
        $encodedMessage = $this->serializer->encode($envelope);
        $messageId = $this->messageMap->getStamp($envelope, DeduplicateStamp::class)?->enabled ?? true ? hash('xxh128', $encodedMessage['body']) : Uuid::v4()->toRfc4122();
        $payload = new Payload($encodedMessage['body'], [
            self::HEADER_MESSAGE_ID => $messageId,
        ]);

        try {
            if (!$this->getStream()->exists()) {
                throw new \RuntimeException('Missing stream: '.$this->streamName);
            }

            $this->client->publish($this->streamName, $payload);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        $envelope = $envelope->with(new TransportMessageIdStamp($messageId));
        $message = $envelope->getMessage();
        $context = [];

        try {
            $context['payload'] = $this->normalizer->normalize($message, null, [
                AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
                AbstractObjectNormalizer::SKIP_UNINITIALIZED_VALUES => true,
            ]);
        } catch (\Throwable $e) {
            $context['payload'] = @serialize($message);
            $context['payload_normalize_error'] = $e;
        }

        $this->log(Level::Info, $envelope, 'Message "{message}" sent to transport', $context);
        $this->delta($envelope, false);

        return $envelope;
    }

    public function getMessageCount(): int
    {
        return $this->getStream()->info()?->state?->messages ?? throw new TransportException('Unable to get message count');
    }

    /**
     * @return array<class-string, int>
     */
    public function getMessageCounts(): array
    {
        $counts = [];

        foreach ($this->counter->keys($prefix = $this->getStreamId().':') as $key) {
            $counts[substr($key, \strlen($prefix))] = $this->counter->get($key) ?? throw new TransportException('Unable to get message count');
        }

        return $counts;
    }

    private function getStream(): Stream
    {
        if (null === $this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
            // note configuration is persisted at server level
            // https://docs.nats.io/nats-concepts/jetstream/streams#configuration
            $this->stream->getConfiguration()
                ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
                ->setStorageBackend(StorageBackend::FILE) // @todo configurable
                ->setDuplicateWindow(self::DEDUPLICATE_WINDOW_SECONDS)
                ->setReplicas(self::STREAM_REPLICAS)
            ;
        }

        return $this->stream;
    }

    private function getConsumer(): Consumer
    {
        if (null === $this->consumer) {
            $this->consumer = $this->getStream()->getConsumer($this->streamName);
            $this->consumer->setIterations(1);
            $this->consumer->setBatching(1);
            // note configuration is persisted at server level
            // https://docs.nats.io/nats-concepts/jetstream/consumers#configuration
            $this->consumer->getConfiguration()
                ->setAckWait(1_000_000_000 * self::ACK_WAIT_SECONDS)
            ;
        }

        return $this->consumer;
    }

    private function delta(Envelope $envelope, bool $acked): void
    {
        try {
            $streamId = $this->getStreamId();
            $keyType = $streamId.':'.$envelope->getMessage()::class;
            $keyId = 'ids-'.$streamId.'-'.$envelope->last(TransportMessageIdStamp::class)?->getId();

            if ($acked) {
                $this->cache->deleteItem($keyId);
                if (0 === $this->counter->delta($keyType, -1)) {
                    $this->counter->clear($keyType);
                }
            } else {
                $cacheItem = $this->cache->getItem($keyId);
                if (!$cacheItem->isHit()) {
                    $cacheItem->set(true);
                    $cacheItem->expiresAfter(self::DEDUPLICATE_WINDOW_SECONDS);
                    $this->cache->save($cacheItem);
                    $this->counter->delta($keyType, 1);
                }
            }
        } catch (\Throwable $e) {
            $this->log(Level::Notice, $envelope, 'Unable to update message counter', ['exception' => $e]);
        }
    }

    private function log(Level $level, null|Consumer|Envelope|Stream $subject, string $message, array $context = []): void
    {
        $context['stream'] = $this->streamName;

        if ($subject instanceof Envelope) {
            $context['message'] = $subject->getMessage()::class;
            $context['message_id'] = $subject->last(TransportMessageIdStamp::class)?->getId();
        } elseif ($subject instanceof Stream) {
            $context['stream_config'] = json_decode(json_encode($subject->info()?->config, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } elseif ($subject instanceof Consumer) {
            $context['consumer_config'] = json_decode(json_encode($subject->info()?->config, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }

        $this->logger->log($level, $message, $context);
    }

    private function getStreamId(): string
    {
        return $this->streamId ??= hash('xxh128', $this->client->configuration->host.':'.$this->client->configuration->port.':'.$this->streamName);
    }

    private function checkMessageCount(): void
    {
        try {
            if (0 === $this->getMessageCount()) {
                $this->counter->clear($this->counter->keys($this->getStreamId().':'));
            }
        } catch (\Throwable $e) {
            $this->log(Level::Notice, null, 'Unable to check message count', ['exception' => $e]);
        }
    }
}
