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
     * @param array<string, string[]>                            $mapping
     * @param array<string, array<string, array<string, mixed>>> $transportOptions
     */
    public function __construct(
        private readonly array $mapping,
        private readonly array $transportOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getTransportOptions(Envelope $envelope): array
    {
        $transport = $envelope->last(ReceivedStamp::class)?->getTransportName() ?? $envelope->last(SentStamp::class)?->getSenderAlias() ?? null;

        return null === $transport ? [] : ($this->transportOptions[$transport][$envelope->getMessage()::class] ?? []);
    }
}
