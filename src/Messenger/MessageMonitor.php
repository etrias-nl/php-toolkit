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
     * @return list<array{transport: string, message: null|class-string, count: null|int, options: array<string, mixed>}>
     */
    public function getJobs(): array
    {
        $jobs = [];

        foreach ($this->receivers as $transport => $receiver) {
            if ($receiver instanceof SyncTransport) {
                continue;
            }

            if ($receiver instanceof MessageCountAwareInterface) {
                $jobs[] = [
                    'transport' => $transport,
                    'message' => null,
                    'count' => $receiver->getMessageCount(),
                    'options' => [],
                ];
            }

            if ($receiver instanceof NatsTransport) {
                foreach ($receiver->getMessageCounts() + array_fill_keys($this->messageMap->getAvailableMessages($transport), 0) as $message => $count) {
                    $jobs[] = [
                        'transport' => $transport,
                        'message' => $message,
                        'count' => $count,
                        'options' => $this->messageMap->getTransportOptions($transport, $message),
                    ];
                }
            } else {
                foreach ($this->messageMap->getAvailableMessages($transport) as $message) {
                    $jobs[] = [
                        'transport' => $transport,
                        'message' => $message,
                        'count' => null,
                        'options' => $this->messageMap->getTransportOptions($transport, $message),
                    ];
                }
            }
        }

        usort($jobs, static fn (array $a, array $b): int => $a['transport'].':'.$a['message'] <=> $b['transport'].':'.$b['message']);

        return $jobs;
    }
}
