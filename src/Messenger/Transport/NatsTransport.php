<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Transport;

use Basis\Nats\Client;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Uid\Uuid;

final class NatsTransport implements TransportInterface, MessageCountAwareInterface
{
    private const HEADER_MESSAGE_ID = 'Nats-Msg-Id';

    private ?Stream $stream = null;
    private ?Consumer $consumer = null;

    public function __construct(
        private readonly Client $client,
        private readonly SerializerInterface $serializer,
        private readonly string $streamName,
    ) {}

    public function get(): iterable
    {
        $receivedMessages = [];

        try {
            $this->connect();
            $this->getConsumer()->handle(function (Payload $payload) use (&$receivedMessages): void {
                $receivedMessages[] = $this->serializer->decode(['body' => $payload->body])
                    ->with(new TransportMessageIdStamp($payload->getHeader(self::HEADER_MESSAGE_ID)))
                ;
            });
        } catch (\Throwable $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return $receivedMessages;
    }

    public function ack(Envelope $envelope): void
    {
        // no-op
    }

    public function reject(Envelope $envelope): void
    {
        // no-op
    }

    public function send(Envelope $envelope): Envelope
    {
        $messageId = Uuid::v4()->toRfc4122();
        $encodedMessage = $this->serializer->encode($envelope);
        $payload = new Payload($encodedMessage['body'], [
            self::HEADER_MESSAGE_ID => $messageId,
        ]);

        try {
            $this->connect();
            $this->client->publish($this->streamName, $payload);
        } catch (\Throwable $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }

        return $envelope->with(new TransportMessageIdStamp($messageId));
    }

    public function getMessageCount(): int
    {
        if (null === $info = $this->getStream()->info()) {
            return 0;
        }

        if (null === $state = $info->getValue('state')) {
            return 0;
        }

        return $state->messages;
    }

    private function connect(): void
    {
        $this->client->ping();
        $this->getStream();
    }

    private function getStream(): Stream
    {
        if (null === $this->stream) {
            $this->stream = $this->client->getApi()->getStream($this->streamName);
            $this->stream->getConfiguration()
                ->setRetentionPolicy(RetentionPolicy::WORK_QUEUE)
                ->setStorageBackend(StorageBackend::MEMORY)
            ;

            $this->stream->createIfNotExists();
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
}
