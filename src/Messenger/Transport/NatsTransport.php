<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Transport;

use Basis\Nats\Client;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Message\Payload;
use Basis\Nats\Queue;
use Basis\Nats\Stream\RetentionPolicy;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\DeduplicateStamp;
use Etrias\PhpToolkit\Messenger\Stamp\FallbackStamp;
use Etrias\PhpToolkit\Messenger\Stamp\RejectDelayStamp;
use Etrias\PhpToolkit\Messenger\Stamp\ReplyToStamp;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

final class NatsTransport implements TransportInterface, MessageCountAwareInterface, SetupableTransportInterface, KeepaliveReceiverInterface
{
    private const HEADER_MESSAGE_ID = 'Nats-Msg-Id';
    private const HEADER_EXPECTED_STREAM = 'Nats-Expected-Stream';
    private const REPLY_TO_FALLBACK = 'fallback';
    private const NANOSECOND = 1_000_000_000;
    private const MICROSECOND = 1_000_000;

    private ?Stream $stream = null;
    private ?Consumer $consumer = null;
    private ?Queue $queue = null;

    /**
     * @param null|(ListableReceiverInterface&MessageCountAwareInterface&TransportInterface) $fallbackTransport
     */
    public function __construct(
        private readonly Client $client,
        private readonly SerializerInterface $serializer,
        private readonly MessageMap $messageMap,
        private readonly LoggerInterface $logger,
        private readonly NormalizerInterface $normalizer,
        private readonly string $streamName,
        private readonly int $replicas,
        private readonly bool $redeliver,
        private readonly float|int $ackWait,
        private readonly float|int $deduplicateWindow,
        private readonly ?TransportInterface $fallbackTransport,
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
        $result = $this->client->api($command, $stream->getConfiguration()->toArray());
        self::assertPayload($result);
        $this->log(Level::Info, $stream, 'Stream setup: {command}', ['command' => $command, 'config' => json_encode($stream->info()?->config)]);

        // setup consumer
        $consumer = $this->getConsumer();
        // create also updates
        $command = 'CONSUMER.DURABLE.CREATE.'.$stream->getName().'.'.$consumer->getName();
        $result = $this->client->api($command, $consumer->getConfiguration()->toArray());
        self::assertPayload($result);
        $this->log(Level::Info, $consumer, 'Consumer setup: {command}', ['command' => $command, 'config' => json_encode($consumer->info()?->config)]);

        if ($this->fallbackTransport instanceof SetupableTransportInterface) {
            $this->fallbackTransport->setup();
        }
    }

    public function get(): array
    {
        try {
            $nextFallbackTs = null;
            foreach ($this->fallbackTransport?->all(1) ?? [] as $envelope) {
                $nextFallbackTs = $envelope->last(FallbackStamp::class)?->sendAt ?? new \DateTimeImmutable();
            }

            if (null !== $nextFallbackTs) {
                $state = $this->getStream()->info()?->state;
                $nextTs = $state?->messages ? new \DateTimeImmutable($state->first_ts) : null;

                if (null === $nextTs || $nextFallbackTs < $nextTs) {
                    foreach ($this->fallbackTransport?->get() ?? [] as $envelope) {
                        return [$envelope->with(new ReplyToStamp(self::REPLY_TO_FALLBACK))];
                    }
                }
            }

            $this->queue ??= $this->getConsumer()->getQueue();
            $message = $this->queue->next();
            $payload = $message->payload;

            if ($payload->isEmpty()) {
                return [];
            }

            $stamps = [new TransportMessageIdStamp($payload->getHeader(self::HEADER_MESSAGE_ID))];
            if (null !== $replyTo = $message->replyTo) {
                $stamps[] = new ReplyToStamp($replyTo, $this->getMessageExpiresAt());
            }

            return [$this->serializer->decode(['body' => $payload->body])->with(...$stamps)];
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

        if (null !== $replyTo->expiresAt && new \DateTimeImmutable() >= $replyTo->expiresAt) {
            $this->log(Level::Warning, $envelope, 'Message "{message}" expired', [
                'expired_at' => $replyTo->expiresAt,
            ]);
        }

        if (self::REPLY_TO_FALLBACK === $replyTo->id) {
            try {
                $this->fallbackTransport?->ack($envelope);
            } catch (\Throwable $e) {
                $this->log(Level::Error, $envelope, new TransportException($e->getMessage(), 0, $e));
            }

            return;
        }

        $retry = false;
        do_ack:
        try {
            $result = $this->client->dispatch($replyTo->id, '+ACK');
            self::assertPayload($result);
        } catch (\Throwable $e) {
            if (!$retry) {
                usleep(self::MICROSECOND / 2);
                $retry = true;

                goto do_ack;
            }

            $this->log(Level::Error, $envelope, new TransportException($e->getMessage(), 0, $e));
        }
    }

    public function reject(Envelope $envelope): void
    {
        if (null === $replyTo = $envelope->last(ReplyToStamp::class)) {
            return;
        }

        $delayMs = $envelope->last(RejectDelayStamp::class)?->milliseconds;

        if (self::REPLY_TO_FALLBACK === $replyTo->id) {
            try {
                if ($this->redeliver) {
                    if (null !== $delayMs) {
                        $this->fallbackTransport?->send($envelope->withoutAll(DelayStamp::class)->with(new DelayStamp($delayMs)));
                        $this->fallbackTransport?->reject($envelope);
                    }
                } else {
                    $this->fallbackTransport?->reject($envelope);
                }
            } catch (\Throwable $e) {
                $this->log(Level::Error, $envelope, new TransportException($e->getMessage(), 0, $e));
            }

            return;
        }

        $payload = [];
        if (null !== $delayMs) {
            $payload['delay'] = $delayMs * self::MICROSECOND;
        }

        $retry = false;
        do_reject:
        try {
            $result = $this->client->dispatch($replyTo->id, '-NAK'.($payload ? ' '.json_encode($payload, JSON_THROW_ON_ERROR) : ''));
            self::assertPayload($result);
        } catch (\Throwable $e) {
            if (!$retry) {
                usleep(self::MICROSECOND / 2);
                $retry = true;

                goto do_reject;
            }

            $this->log(Level::Error, $envelope, new TransportException($e->getMessage(), 0, $e));
        }
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        if (null === $replyTo = $envelope->last(ReplyToStamp::class)) {
            return;
        }

        $replyTo->expiresAt = $this->getMessageExpiresAt();

        if (self::REPLY_TO_FALLBACK === $replyTo->id) {
            try {
                if ($this->fallbackTransport instanceof KeepaliveReceiverInterface) {
                    $this->fallbackTransport->keepalive($envelope, (int) max(1, $this->ackWait));
                }
            } catch (\Throwable $e) {
                $this->log(Level::Error, $envelope, new TransportException($e->getMessage(), 0, $e));
            }

            return;
        }

        $retry = false;
        do_keepalive:
        try {
            $result = $this->client->dispatch($replyTo->id, '+WPI');
            self::assertPayload($result);
        } catch (\Throwable $e) {
            if (!$retry) {
                usleep(self::MICROSECOND / 2);
                $retry = true;

                goto do_keepalive;
            }

            $this->log(Level::Error, $envelope, new TransportException($e->getMessage(), 0, $e));
        }
    }

    public function send(Envelope $envelope): Envelope
    {
        $envelope = $envelope->withoutAll(TransportMessageIdStamp::class);
        $encodedMessage = $this->serializer->encode($envelope);
        $messageId = $this->messageMap->getStamp($envelope, DeduplicateStamp::class)?->enabled ?? true ? hash('xxh128', $encodedMessage['body']) : Uuid::v7()->toBase58();
        $payload = new Payload($encodedMessage['body'], [
            self::HEADER_MESSAGE_ID => $messageId,
            self::HEADER_EXPECTED_STREAM => $this->streamName,
        ]);
        $context = [];

        try {
            $context['payload'] = json_encode($this->normalizer->normalize($envelope->getMessage(), null, [
                AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
                AbstractObjectNormalizer::SKIP_UNINITIALIZED_VALUES => true,
            ]));
        } catch (\Throwable $e) {
            $context['payload'] = @serialize($envelope->getMessage());
            $context['payload_normalize_exception'] = $e;
        }

        $retry = false;
        do_send:
        try {
            $result = $this->client->dispatch($this->streamName, $payload);
            self::assertPayload($result);
            $context['duplicate'] = $result->getValue('duplicate');
        } catch (\Throwable $e) {
            if (!$retry) {
                usleep(self::MICROSECOND / 2);
                $retry = $context['retry'] = true;

                goto do_send;
            }

            if (null !== $this->fallbackTransport) {
                $context['fallback'] = true;

                try {
                    $envelope = $envelope->withoutAll(FallbackStamp::class)->with(new FallbackStamp(new \DateTimeImmutable()));
                    $messageId = $this->fallbackTransport->send($envelope)->last(TransportMessageIdStamp::class)?->getId();

                    goto sent;
                } catch (\Throwable $fallbackException) {
                    $context['fallback_exception'] = $fallbackException;
                }
            }

            $exception = new TransportException($e->getMessage(), 0, $e);

            $this->log(Level::Error, $envelope, $exception, $context);

            throw $exception;
        }

        sent:
        $envelope = $envelope->with(new TransportMessageIdStamp($messageId));

        $this->log(Level::Info, $envelope, 'Message "{message}" sent to transport', $context);

        return $envelope;
    }

    public function getMessageCount(): int
    {
        $count = $this->getStream()->info()?->state?->messages ?? throw new TransportException('Unable to get message count');

        return $count + ($this->fallbackTransport?->getMessageCount() ?? 0);
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
                ->setReplicas($this->replicas)
                ->setDuplicateWindow((float) $this->deduplicateWindow)
            ;
        }

        return $this->stream;
    }

    private function getConsumer(): Consumer
    {
        if (null === $this->consumer) {
            $this->consumer = $this->getStream()->getConsumer($this->streamName);
            $this->consumer->setBatching(1);
            $this->consumer->setExpires(.0);
            // note configuration is persisted at server level
            // https://docs.nats.io/nats-concepts/jetstream/consumers#configuration
            $this->consumer->getConfiguration()
                ->setAckWait((int) (self::NANOSECOND * $this->ackWait))
                ->setMaxAckPending(-1)
                ->setMaxDeliver($this->redeliver ? -1 : 1)
                ->setMaxWaiting(10_000)
            ;
        }

        return $this->consumer;
    }

    private function getMessageExpiresAt(): ?\DateTimeImmutable
    {
        return $this->redeliver ? new \DateTimeImmutable('+'.(int) (self::MICROSECOND * $this->ackWait).' microseconds') : null;
    }

    private function log(Level $level, mixed $subject, string|\Throwable $message, array $context = []): void
    {
        $context['stream'] = $this->streamName;

        if ($subject instanceof Envelope) {
            $context['message'] = $subject->getMessage()::class;
            $context['message_id'] = $subject->last(TransportMessageIdStamp::class)?->getId();
        }

        if ($message instanceof \Throwable) {
            $context['exception'] = $message;
            $message = $message->getMessage();
        }

        $this->logger->log($level, $message, $context);
    }

    private function unsubscribe(): void
    {
        if (null === $this->queue) {
            return;
        }

        try {
            $this->client->unsubscribe($this->queue);
        } catch (\Throwable $e) {
            $this->log(Level::Error, null, new TransportException($e->getMessage(), 0, $e));
        }

        $this->queue = null;
    }
}
