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
    private const ACK_WAIT_SECONDS = 300;

    private ?Stream $stream = null;
    private ?Consumer $consumer = null;
    private ?string $counterPrefix = null;

    public function __construct(
        private readonly Client $client,
        private readonly SerializerInterface $serializer,
        private readonly MessageMap $messageMap,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger,
        private readonly NormalizerInterface $normalizer,
        private readonly string $streamName,
    ) {}

    public function setup(bool $refresh = false, bool $dryRun = false): void
    {
        $this->client->ping();

        $stream = $this->getStream();

        if ($stream->exists()) {
            if ($refresh) {
                if (!$dryRun) {
                    $stream->update();
                }

                $this->log(Level::Info, null, 'Stream updated');
            } else {
                $this->log(Level::Info, null, 'Stream already exists');
            }
        } else {
            if (!$dryRun) {
                $stream->create();
            }

            $this->log(Level::Info, null, 'Stream created');
        }

        $consumer = $this->getConsumer();

        if ($consumer->exists()) {
            if ($refresh) {
                if (!$dryRun) {
                    // create also updates consumers, but PHP client cannot force it
                    $this->client->api('CONSUMER.DURABLE.CREATE.'.$stream->getName().'.'.$consumer->getName(), $consumer->getConfiguration()->toArray()) ?? throw new TransportException('Unable to update consumer');
                }

                $this->log(Level::Info, null, 'Consumer recreated');
            } else {
                $this->log(Level::Info, null, 'Consumer already exists');
            }
        } else {
            if (!$dryRun) {
                $consumer->create();
            }

            $this->log(Level::Info, null, 'Consumer created');
        }
    }

    public function get(): array
    {
        $receivedMessages = [];

        try {
            $this->client->ping();
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
            $this->log(Level::Warning, $envelope, 'Message expired', [
                'expired_at' => $replyTo->expiresAt,
            ]);
        }

        try {
            $this->client->ping();
            $this->client->publish($replyTo->id, true);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        $this->delta($envelope, -1);
    }

    /**
     * @see https://github.com/symfony/symfony/issues/52642
     */
    public function reject(Envelope $envelope): void
    {
        $this->log(Level::Notice, $envelope, 'Message rejected');
    }

    public function send(Envelope $envelope): Envelope
    {
        $envelope = $envelope->withoutAll(TransportMessageIdStamp::class);
        $encodedMessage = $this->serializer->encode($envelope);
        $options = $this->messageMap->getTransportOptionsFromEnvelope($envelope);
        $messageId = $options['deduplicate'] ?? true ? hash('xxh128', $encodedMessage['body']) : Uuid::v4()->toRfc4122();
        $payload = new Payload($encodedMessage['body'], [
            self::HEADER_MESSAGE_ID => $messageId,
        ]);
        $stream = $this->getStream();

        try {
            $this->client->ping();

            $stream->createIfNotExists();
            if (!$stream->exists()) {
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

        $this->log(Level::Info, $envelope, 'Message sent', $context);
        $this->delta($envelope, 1);

        return $envelope;
    }

    public function getMessageCount(): int
    {
        $this->client->ping();

        return $this->getStream()->info()?->state?->messages ?? throw new TransportException('Unable to get message count');
    }

    /**
     * @return array<class-string, int>
     */
    public function getMessageCounts(): array
    {
        /** @var array<class-string, int> $counts */
        $counts = $this->counter->values($counterPrefix = $this->getCounterPrefix());

        if (0 === $this->getMessageCount()) {
            foreach ($counts as $message => $_) {
                $this->counter->clear($counterPrefix.$message);
            }

            return [];
        }

        return array_map(static fn (int $count): int => max(0, $count), $counts);
    }

    private function getStream(): Stream
    {
        if (null === $this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
            $this->stream->getConfiguration()
                ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
                ->setStorageBackend(StorageBackend::MEMORY)
                ->setDuplicateWindow(10.0)
            ;
        }

        return $this->stream;
    }

    private function getConsumer(): Consumer
    {
        if (null === $this->consumer) {
            $this->consumer = $this->getStream()->getConsumer($this->streamName);
            $this->consumer->setIterations(1);
            $this->consumer->getConfiguration()
                ->setAckWait(1_000_000_000 * self::ACK_WAIT_SECONDS)
            ;
        }

        return $this->consumer;
    }

    private function delta(Envelope $envelope, int $count): void
    {
        try {
            $this->counter->delta($this->getCounterPrefix().$envelope->getMessage()::class, $count);
        } catch (\Throwable $e) {
            $this->log(Level::Notice, $envelope, 'Unable to update message counter', ['exception' => $e]);
        }
    }

    private function log(Level $level, ?Envelope $envelope, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, [
            'stream' => $this->streamName,
            'message' => null === $envelope ? null : $envelope->getMessage()::class,
            'message_id' => $envelope?->last(TransportMessageIdStamp::class)?->getId(),
        ] + $context);
    }

    private function getCounterPrefix(): string
    {
        return $this->counterPrefix ??= hash('xxh128', $this->client->configuration->host.':'.$this->client->configuration->port.':'.$this->streamName).':';
    }
}
