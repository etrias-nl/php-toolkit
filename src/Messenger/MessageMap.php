<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * @internal
 */
final class MessageMap
{
    /**
     * @template T of StampInterface
     *
     * @param array<string, array<class-string, array<string, mixed>>> $mapping
     * @param array<class-string, array<class-string<T>, list<T>>>     $defaultStamps
     */
    public function __construct(
        private readonly array $mapping,
        private readonly array $defaultStamps = [],
    ) {}

    /**
     * @return class-string[]
     */
    public function getAvailableMessages(string $transport): array
    {
        return array_keys($this->mapping[$transport] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransportOptions(string $transport, string $message): array
    {
        return $this->mapping[$transport][$message] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransportOptionsFromEnvelope(Envelope $envelope): array
    {
        $transport = $envelope->last(SentStamp::class)?->getSenderAlias() ?? $envelope->last(ReceivedStamp::class)?->getTransportName();

        return null === $transport ? [] : $this->getTransportOptions($transport, $envelope->getMessage()::class);
    }

    /**
     * @template T of StampInterface
     *
     * @param class-string<T> $stamp
     *
     * @return list<T>
     */
    public function getStamps(Envelope $envelope, string $stamp): array
    {
        return $envelope->all($stamp) ?: $this->defaultStamps[$envelope->getMessage()::class][$stamp] ?? [];
    }

    /**
     * @template T of StampInterface
     *
     * @param class-string<T> $stamp
     *
     * @return null|T
     */
    public function getStamp(Envelope $envelope, string $stamp): ?StampInterface
    {
        $stamps = $this->getStamps($envelope, $stamp);

        return end($stamps) ?: null;
    }
}
