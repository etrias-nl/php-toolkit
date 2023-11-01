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

    /**
     * @return array<string, array<string, int>>
     */
    public function getCounts(): array
    {
        $counts = [];

        foreach ($this->receivers as $name => $receiver) {
            $isEmpty = $receiver instanceof MessageCountAwareInterface && 0 === $receiver->getMessageCount();
            $messageCounts = [];
            foreach ($this->transportOptions[$name] ?? [] as $message => $_) {
                $counter = sprintf(self::COUNTER_KEY, $name, $message);
                if ($isEmpty) {
                    $this->counter->clear($counter);
                }

                $messageCounts[$message] = $this->counter->get($counter);
            }

            if ($messageCounts) {
                arsort($messageCounts, SORT_NUMERIC);
                $counts[$name] = $messageCounts;
            }
        }

        ksort($counts);

        return $counts;
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
