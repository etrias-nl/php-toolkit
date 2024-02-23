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

        // setup consumer
        $consumer = $this->getConsumer();
        $consumerCreated = $consumer->exists() ? null : $consumer->create();
        $consumerConfig = $consumer->info()?->config;

        $this->log(Level::Info, $consumer, $consumerCreated ? 'Consumer created' : 'Consumer already exists', ['config' => json_encode($consumerConfig)]);

        // verify config
        $mismatches = [];

        if ($streamConfig?->duplicate_window !== $deduplicateWindowClient = ($this->deduplicateWindow * self::NANOSECOND)) {
            $mismatches['deduplicate_window'] = ['client' => $deduplicateWindowClient, 'server' => $streamConfig?->duplicate_window];
        }
        if ($consumerConfig?->ack_wait !== $ackWaitClient = ($this->ackWait * self::NANOSECOND)) {
            $mismatches['ack_wait'] = ['client' => $ackWaitClient, 'server' => $consumerConfig?->ack_wait];
        }
        if ($mismatches) {
            throw new TransportException('Server/client configuration mismatch: '.json_encode($mismatches));
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

        $envelope = $envelope->with(new TransportMessageIdStamp($messageId));
        $sequence = $context['sequence'] = $result->getValue('seq');

        $this->log(Level::Info, $envelope, 'Message "{message}" sent to transport', $context);
        $this->delta($envelope, false, $sequence);

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
            $count = $this->counter->get($key) ?? -1;
            $counts[substr($key, \strlen($prefix))] = $count < 0 ? null : $count;
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

    private function delta(Envelope $envelope, bool $acked, ?int $sequence = null): void
    {
        try {
            $streamId = $this->getStreamId();
            $keyType = $streamId.':'.$envelope->getMessage()::class;
            $keyAck = 'ack:'.$streamId.':'.$envelope->last(TransportMessageIdStamp::class)?->getId();
            $keySequence = 'sequence:'.$streamId;

            if ($acked) {
                if (null !== $this->counter->get($keyAck)) {
                    $this->counter->delta($keyType, -1)
                    $this->counter->clear($keyAck);
                }
            } else {
                $currentSequence = $this->counter->get($keySequence) ?? 0;
                $sequence ??= $currentSequence + 1;
                if ($sequence > $currentSequence) {
                    $this->counter->delta($keySequence, $sequence - $currentSequence);
                    $this->counter->delta($keyType, 1);
                    $this->counter->delta($keyAck, 1);
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
