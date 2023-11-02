<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

final class MessageMonitor
{
    /**
     * @param iterable<string, ReceiverInterface> $receivers
     */
    public function __construct(
        private readonly MessageMap $messageMap,
        #[TaggedIterator(tag: 'messenger.receiver', indexAttribute: 'alias')]
        private readonly iterable $receivers,
    ) {}

    /**
     * @return array<string, null|int>
     */
    public function getJobs(): array
    {
        $jobs = [];

        foreach ($this->receivers as $transport => $receiver) {
            if ($receiver instanceof SyncTransport) {
                continue;
            }

            if ($receiver instanceof NatsTransport) {
                foreach ($receiver->getMessageCounts() as $message => $count) {
                    $jobs[$transport.':'.$message] = $count;
                }
                foreach ($this->messageMap->getAvailableMessages($transport) as $message) {
                    $jobs[$transport.':'.$message] ??= 0;
                }

                continue;
            }

            if ($receiver instanceof MessageCountAwareInterface) {
                $jobs[$transport] = $receiver->getMessageCount();
            }

            foreach ($this->messageMap->getAvailableMessages($transport) as $message) {
                $jobs[$transport.':'.$message] = null;
            }
        }

        uksort($jobs, static fn (string $a, string $b): int => $jobs[$b] <=> $jobs[$a] ?: $a <=> $b);

        return $jobs;
    }
}
