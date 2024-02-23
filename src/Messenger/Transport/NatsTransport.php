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
    private const HEADER_EXPECTED_STREAM = 'Nats-Expected-Stream';
    private const NANOSECOND = 1_000_000_000;

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
        private readonly int $ackWait,
        private readonly int $deduplicateWindow,
    ) {}

    public function setup(): void
    {
        // setup stream
        $stream = $this->getStream();
        $streamCreated = $stream->exists() ? null : $stream->create();
        $streamConfig = $stream->info()?->config;

        $this->log(Level::Info, $stream, $streamCreated ? 'Stream created' : 'Stream already exists', ['config' => json_encode($streamConfig)]);

        // verify stream
        if ($streamConfig?->duplicate_window !== ($this->deduplicateWindow * self::NANOSECOND)) {
            $this->logger->error('Stream configuration mismatch: duplicate_window', ['client' => $this->deduplicateWindow * self::NANOSECOND, 'server' => $streamConfig?->duplicate_window]);
        }

        // setup consumer
        $consumer = $this->getConsumer();
        $consumerCreated = $consumer->exists() ? null : $consumer->create();
        $consumerConfig = $consumer->info()?->config;

        $this->log(Level::Info, $consumer, $consumerCreated ? 'Consumer created' : 'Consumer already exists', ['config' => json_encode($consumerConfig)]);

        // verify consumer
        if ($consumerConfig?->ack_wait !== ($this->ackWait * self::NANOSECOND)) {
            $this->logger->error('Consumer configuration mismatch: ack_wait', ['client' => $this->ackWait * self::NANOSECOND, 'server' => $consumerConfig?->ack_wait]);
        }
    }

    public function get(): array
    {
        try {
            if (0 === $this->getMessageCount()) {
                $this->counter->clear($this->counter->keys($this->getStreamId().':'));
            }
        } catch (\Throwable) {
        }

        $receivedMessages = [];

        try {
            $this->getConsumer()->handle(function (Payload $payload, ?string $replyTo) use (&$receivedMessages): void {
                $stamps = [new TransportMessageIdStamp($payload->getHeader(self::HEADER_MESSAGE_ID))];
                if (null !== $replyTo) {
                    $stamps[] = new ReplyToStamp($replyTo, new \DateTimeImmutable('+'.$this->ackWait.' seconds'));
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

    public function reject(Envelope $envelope): void {}

    public function send(Envelope $envelope): Envelope
    {
        $envelope = $envelope->withoutAll(TransportMessageIdStamp::class);
        $encodedMessage = $this->serializer->encode($envelope);
        $messageId = $this->messageMap->getStamp($envelope, DeduplicateStamp::class)?->enabled ?? true ? hash('xxh128', $encodedMessage['body']) : Uuid::v4()->toRfc4122();
        $payload = new Payload($encodedMessage['body'], [
            self::HEADER_MESSAGE_ID => $messageId,
            self::HEADER_EXPECTED_STREAM => $this->streamName,
        ]);
        $context = [];

        try {
            $context['payload'] = $this->normalizer->normalize($envelope->getMessage(), null, [
                AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
                AbstractObjectNormalizer::SKIP_UNINITIALIZED_VALUES => true,
            ]);
        } catch (\Throwable $e) {
            $context['payload'] = @serialize($envelope->getMessage());
            $context['payload_normalize_error'] = $e;
        }

        try {
            $result = $this->client->dispatch($this->streamName, $payload);
            self::assertPayload($result);
        } catch (\Throwable $e) {
            $this->log(Level::Error, $envelope, 'Unable to send message "{message}": '.$e->getMessage(), ['exception' => $e] + $context);

            throw new TransportException($e->getMessage(), 0, $e);
        }

        $context['sequence'] = $result->getValue('seq');
        $envelope = $envelope->with(new TransportMessageIdStamp($messageId));

        $this->log(Level::Info, $envelope, 'Message "{message}" sent to transport', $context);
        $this->delta($envelope, false);

        return $envelope;
    }

    public function getMessageCount(): int
    {
        return $this->getStream()->info()?->state?->messages ?? throw new TransportException('Unable to get message count');
    }

    /**
     * @return array<class-string, null|int>
     */
    public function getMessageCounts(): array
    {
        $counts = [];

        foreach ($this->counter->keys($prefix = $this->getStreamId().':') as $key) {
            $counts[substr($key, \strlen($prefix))] = $this->counter->get($key);
        }

        return $counts;
    }

    /**
     * @psalm-assert Payload $payload
     */
    private static function assertPayload(mixed $payload): void
    {
        if (!$payload instanceof Payload) {
            throw new \RuntimeException('Expected payload, got '.get_debug_type($payload));
        }
        if (null !== $error = $payload->getValue('error')) {
            $message = $error->description ?? $payload->body;
            if (isset($error->err_code)) {
                $message .= ' ['.$error->err_code.']';
            }

            throw new \RuntimeException($message);
        }
    }

    private function getStream(): Stream
    {
        if (null === $this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
            // note configuration is persisted at server level
            // https://docs.nats.io/nats-concepts/jetstream/streams#configuration
            $this->stream->getConfiguration()
                ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
                ->setStorageBackend(StorageBackend::FILE)
                ->setDuplicateWindow($this->deduplicateWindow)
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
                ->setAckWait(self::NANOSECOND * $this->ackWait)
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
                    $cacheItem->expiresAfter($this->deduplicateWindow);
                    $this->cache->save($cacheItem);
                    $this->counter->delta($keyType, 1);
                }
            }
        } catch (\Throwable $e) {
            $this->log(Level::Notice, $envelope, 'Unable to update message counter', ['exception' => $e]);
        }
    }

    private function log(Level $level, mixed $subject, string $message, array $context = []): void
    {
        $context['stream'] = $this->streamName;

        if ($subject instanceof Envelope) {
            $context['message'] = $subject->getMessage()::class;
            $context['message_id'] = $subject->last(TransportMessageIdStamp::class)?->getId();
        }

        $this->logger->log($level, $message, $context);
    }

    private function getStreamId(): string
    {
        return $this->streamId ??= hash('xxh128', $this->client->configuration->host.':'.$this->client->configuration->port.':'.$this->streamName);
    }
}
