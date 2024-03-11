<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Transport;

use Basis\Nats\Client;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Message\Payload;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;
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
    private const MICROSECOND = 1_000_000;

    private ?Stream $stream = null;
    private ?Consumer $consumer = null;
    private ?string $subscription = null;

    public function __construct(
        private readonly Client $client,
        private readonly SerializerInterface $serializer,
        private readonly MessageMap $messageMap,
        private readonly LoggerInterface $logger,
        private readonly NormalizerInterface $normalizer,
        private readonly string $streamName,
        private readonly float|int $ackWait,
        private readonly float|int $deduplicateWindow,
    ) {}

    public function __destruct()
    {
        $this->unsubscribe();
    }

    public function setup(): void
    {
        // setup stream
        $stream = $this->getStream();
        $command = ($stream->exists() ? 'STREAM.UPDATE.' : 'STREAM.CREATE.').$stream->getName();
        $this->client->api($command, $stream->getConfiguration()->toArray());
        $this->log(Level::Info, $stream, 'Stream setup: {command}', ['command' => $command, 'config' => json_encode($stream->info()?->config)]);

        // setup consumer
        $consumer = $this->getConsumer();
        // create also updates
        $command = 'CONSUMER.DURABLE.CREATE.'.$stream->getName().'.'.$consumer->getName();
        $this->client->api($command, $consumer->getConfiguration()->toArray());
        $this->log(Level::Info, $consumer, 'Consumer setup: {command}', ['command' => $command, 'config' => json_encode($consumer->info()?->config)]);
    }

    public function get(): array
    {
        try {
            if (null === $this->subscription) {
                $this->subscription = 'handler.'.bin2hex(random_bytes(4));
                $this->client->subscribe($this->subscription, function (Payload $payload, ?string $replyTo): ?Envelope {
                    if ($payload->isEmpty()) {
                        return null;
                    }

                    $stamps = [new TransportMessageIdStamp($payload->getHeader(self::HEADER_MESSAGE_ID))];
                    if (null !== $replyTo) {
                        $stamps[] = new ReplyToStamp($replyTo, new \DateTimeImmutable('+'.(int) (self::MICROSECOND * $this->ackWait).' microseconds'));
                    }

                    return $this->serializer->decode(['body' => $payload->body])->with(...$stamps);
                });
            }

            $this->client->publish(
                '$JS.API.CONSUMER.MSG.NEXT.'.$this->streamName.'.'.$this->streamName,
                ['batch' => 1, 'no_wait' => true],
                $this->subscription
            );

            if (null === $receivedMessage = $this->client->process(120, false)) {
                return [];
            }

            return [$receivedMessage];
        } catch (\Throwable $e) {
            $this->unsubscribe();

            throw new TransportException($e->getMessage(), 0, $e);
        }
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
            $this->client->publish($replyTo->id, '+ACK');
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    public function reject(Envelope $envelope): void
    {
        if (null === $replyTo = $envelope->last(ReplyToStamp::class)) {
            return;
        }

        try {
            $this->client->publish($replyTo->id, '-NAK');
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

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
            $this->client->publish($this->streamName, $payload);
        } catch (\Throwable $e) {
            $this->log(Level::Error, $envelope, 'Unable to send message "{message}": '.$e->getMessage(), ['exception' => $e] + $context);

            throw new TransportException($e->getMessage(), 0, $e);
        }

        $envelope = $envelope->with(new TransportMessageIdStamp($messageId));

        $this->log(Level::Info, $envelope, 'Message "{message}" sent to transport', $context);

        return $envelope;
    }

    public function getMessageCount(): int
    {
        return $this->getStream()->info()?->state?->messages ?? throw new TransportException('Unable to get message count');
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
                ->setDuplicateWindow((float) $this->deduplicateWindow)
            ;
        }

        return $this->stream;
    }

    private function getConsumer(): Consumer
    {
        if (null === $this->consumer) {
            $this->consumer = $this->getStream()->getConsumer($this->streamName);
            // note configuration is persisted at server level
            // https://docs.nats.io/nats-concepts/jetstream/consumers#configuration
            $this->consumer->getConfiguration()
                ->setAckWait((int) (self::NANOSECOND * $this->ackWait))
            ;
        }

        return $this->consumer;
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

    private function unsubscribe(): void
    {
        if (null === $this->subscription) {
            return;
        }

        try {
            $this->client->unsubscribe($this->subscription);
        } catch (\Throwable) {
        }

        $this->subscription = null;
    }
}
