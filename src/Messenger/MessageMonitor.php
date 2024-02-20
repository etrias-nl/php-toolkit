<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Exception\TransportException;
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
                try {
                    $count = $receiver->getMessageCount();
                } catch (TransportException) {
                    $count = null;
                }

                $jobs[] = [
                    'transport' => $transport,
                    'message' => null,
                    'count' => $count,
                    'options' => [],
                ];
            }

            $availableMessages = $this->messageMap->getAvailableMessages($transport);

            if ($receiver instanceof NatsTransport) {
                try {
                    $counts = $receiver->getMessageCounts();
                } catch (TransportException) {
                    $counts = [];
                }

                $counts += array_fill_keys($availableMessages, 0);
            } else {
                $counts = array_fill_keys($availableMessages, null);
            }

            foreach ($counts as $message => $count) {
                $jobs[] = [
                    'transport' => $transport,
                    'message' => $message,
                    'count' => $count,
                    'options' => $this->messageMap->getTransportOptions($transport, $message),
                ];
            }
        }

        usort($jobs, static fn (array $a, array $b): int => $a['transport'].':'.$a['message'] <=> $b['transport'].':'.$b['message']);

        return $jobs;
    }
}
