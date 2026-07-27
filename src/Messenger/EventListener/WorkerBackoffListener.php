<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\EventListener;

use Doctrine\DBAL\Exception\ConnectionException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * RejectDelayListener already delays the failed message itself, but the worker picks up the next
 * one immediately. While a service is unreachable that turns into hundreds of connection attempts
 * per second, all of them doomed. This pauses the worker instead, longer with every failure in a row.
 */
final class WorkerBackoffListener implements EventSubscriberInterface
{
    private const int START_MS = 250;
    private const int MAX_MS = 30_000;
    private const int MAX_SHIFT = 20;

    private int $failures = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->isConnectivityFailure($event->getThrowable())) {
            return;
        }

        ++$this->failures;
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if ($this->failures > 0) {
            $this->logger->notice('Worker resumed after {failures} connectivity failures', [
                'failures' => $this->failures,
                'receiver' => $event->getReceiverName(),
            ]);
        }

        $this->failures = 0;
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (0 === $this->failures) {
            return;
        }

        $delayMs = min(self::MAX_MS, self::START_MS << min($this->failures - 1, self::MAX_SHIFT));
        // Without jitter every worker backs off in lockstep and they all hit the failing service again
        // at the same moment, which is the spike we are trying to get rid of.
        $delayMs = random_int(intdiv($delayMs, 2), $delayMs);

        if (1 === $this->failures) {
            $this->logger->notice('Worker backing off after a connectivity failure', ['delay_ms' => $delayMs]);
        }

        usleep($delayMs * 1000);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    private function isConnectivityFailure(\Throwable $exception): bool
    {
        if ($exception instanceof HandlerFailedException) {
            foreach ($exception->getWrappedExceptions(null, true) as $wrappedException) {
                if ($this->isConnectivityFailure($wrappedException)) {
                    return true;
                }
            }
        }

        if ($exception instanceof NetworkExceptionInterface
            || $exception instanceof TransportExceptionInterface
            || $exception instanceof ConnectionException
        ) {
            return true;
        }

        $previousException = $exception->getPrevious();

        return null !== $previousException && $this->isConnectivityFailure($previousException);
    }
}
