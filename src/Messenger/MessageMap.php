<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;

/**
 * @internal
 */
final class MessageMap
{
    /**
     * @param array<string, array<class-string, array<string, mixed>>> $mapping
     */
    public function __construct(
        private readonly array $mapping,
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
    public function getTransportOptions(Envelope $envelope): array
    {
        $transport = $envelope->last(ReceivedStamp::class)?->getTransportName() ?? $envelope->last(SentStamp::class)?->getSenderAlias() ?? null;

        return null === $transport ? [] : ($this->mapping[$transport][$envelope->getMessage()::class] ?? []);
    }
}
