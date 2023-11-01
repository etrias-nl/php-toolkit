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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

final class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface
{
    private const HEADER_MESSAGE_ID = 'Nats-Msg-Id';

    private ?Stream $stream = null;
    private ?Consumer $consumer = null;
    private ?string $counterPrefix = null;

    /**
     * @param array<string, array<string, array<string, mixed>>> $transportOptions
     */
    public function __construct(
        private readonly Client $client,
        private readonly SerializerInterface $serializer,
        private readonly string $streamName,
        private readonly array $transportOptions,
        private readonly Counter $counter,
    ) {}

    public function setup(): void
    {
        $this->client->ping();

        $this->getStream()->createIfNotExists();
    }

    public function get(): iterable
    {
        $receivedMessages = [];

        try {
            $this->client->ping();
            $this->getConsumer()->handle(function (Payload $payload) use (&$receivedMessages): void {
                $receivedMessages[] = $envelope = $this->serializer->decode(['body' => $payload->body])
                    ->with(new TransportMessageIdStamp($payload->getHeader(self::HEADER_MESSAGE_ID)))
                ;

                $this->delta($envelope, -1);
            });
        } catch (\Throwable $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return $receivedMessages;
    }

    public function ack(Envelope $envelope): void
    {
        // no-op: acked on read
    }

    public function reject(Envelope $envelope): void
    {
        // no-op: acked on read
    }

    public function send(Envelope $envelope): Envelope
    {
        $envelope = $envelope->withoutAll(TransportMessageIdStamp::class);
        $encodedMessage = $this->serializer->encode($envelope);
        $options = $this->getTransportOptions($envelope);
        $messageId = $options['deduplicate'] ?? true ? hash('xxh128', $encodedMessage['body']) : Uuid::v4()->toRfc4122();
        $payload = new Payload($encodedMessage['body'], [
            self::HEADER_MESSAGE_ID => $messageId,
        ]);

        try {
            $this->client->ping();
            $this->client->publish($this->streamName, $payload);
        } catch (\Throwable $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        $this->delta($envelope, 1);

        return $envelope->with(new TransportMessageIdStamp($messageId));
    }

    public function getMessageCount(): int
    {
        $this->client->ping();

        return $this->getStream()->info()?->getValue('state')?->messages ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function getMessageCounts(): array
    {
        $counts = $this->counter->values($this->getCounterPrefix());

        if (0 === $this->getMessageCount()) {
            foreach ($counts as $key => $_) {
                $this->counter->clear($key);
            }

            return [];
        }

        krsort($counts);

        return $counts;
    }

    private function getStream(): Stream
    {
        if (null === $this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
            $this->stream->getConfiguration()
                ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
                ->setStorageBackend(StorageBackend::MEMORY)
            ;
        }

        return $this->stream;
    }

    private function getConsumer(): Consumer
    {
        if (null === $this->consumer) {
            $this->consumer = $this->getStream()->getConsumer($this->streamName);
            $this->consumer->setIterations(1);
        }

        return $this->consumer;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTransportOptions(Envelope $envelope): array
    {
        if (null === $sender = $envelope->last(SentStamp::class)?->getSenderAlias()) {
            return [];
        }

        return $this->transportOptions[$sender][$envelope->getMessage()::class] ?? [];
    }

    private function delta(Envelope $envelope, int $count): void
    {
        $this->counter->delta($this->getCounterPrefix().$envelope->getMessage()::class, $count);
    }

    private function getCounterPrefix(): string
    {
        return $this->counterPrefix ??= hash('xxh128', $this->client->configuration->host.':'.$this->client->configuration->port.':'.$this->streamName).':';
    }
}
