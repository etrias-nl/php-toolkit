<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Etrias\PhpToolkit\Counter\Counter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class EnvelopeRegistry
{
    private const COUNTER_KEY = '%s:%s';

    /**
     * @param iterable<string, ReceiverInterface>                $receivers
     * @param array<string, array<string, array<string, mixed>>> $transportOptions
     */
    public function __construct(
        #[TaggedIterator(tag: 'messenger.receiver', indexAttribute: 'alias')]
        private readonly iterable $receivers,
        #[Autowire(param: 'php_toolkit.messenger.transport_options')]
        private readonly array $transportOptions,
        private readonly Counter $counter,
    ) {}

    public function delta(string $sender, Envelope $envelope, int $count): int
    {
        return $this->counter->delta(sprintf(self::COUNTER_KEY, $sender, $envelope->getMessage()::class), $count);
    }

    public function getTransportOptions(string $sender, Envelope $envelope): array
    {
        return $this->transportOptions[$sender][$envelope->getMessage()::class] ?? [];
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        $counts = $this->counter->values();
        $receivers = $this->receivers instanceof \Traversable ? iterator_to_array($this->receivers) : $this->receivers;

        foreach ($counts as $key => $_) {
            [$sender] = explode(':', $key, 2);
            $receiver = $receivers[$sender] ?? null;

            if ($receiver instanceof MessageCountAwareInterface && 0 === $receiver->getMessageCount()) {
                $this->counter->clear($key);
                $counts[$key] = 0;
            }
        }

        foreach ($this->transportOptions as $sender => $messages) {
            foreach ($messages as $message => $_) {
                $counts[sprintf(self::COUNTER_KEY, $sender, $message)] ??= 0;
            }
        }

        uksort($counts, static fn (string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: $a <=> $b);

        return $counts;
    }

    public function getSenderAlias(Envelope $envelope): string
    {
        return $envelope->last(SentStamp::class)?->getSenderAlias() ?? $envelope->last(ReceivedStamp::class)?->getTransportName() ?? 'sync';
    }
}
