<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Etrias\PhpToolkit\Counter\Counter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;

final class EnvelopeRegistry
{
    private const COUNTER_KEY = 'counter:messenger:%s:%s';

    /**
     * @param array<string, array<string, array<string, mixed>>> $transportOptions
     */
    public function __construct(
        #[Autowire(param: 'php_toolkit.messenger.transport_options')]
        private readonly array $transportOptions,
        private readonly Counter $counter,
    ) {}

    public function delta(string $sender, Envelope $envelope, int $count): int
    {
        return $this->counter->delta(sprintf(self::COUNTER_KEY, $sender, $envelope->getMessage()::class), $count);
    }

    public function getSenderAlias(Envelope $envelope): string
    {
        return $envelope->last(SentStamp::class)?->getSenderAlias() ?? $envelope->last(ReceivedStamp::class)?->getTransportName() ?? 'sync';
    }

    public function getTransportOptions(Envelope $envelope): array
    {
        return $this->transportOptions[$this->getSenderAlias($envelope)][$envelope->getMessage()::class] ?? [];
    }
}
