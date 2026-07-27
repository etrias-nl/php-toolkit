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

/**
 * Losing a service inside the cluster usually means a stale connection or stale DNS, and the worker
 * cannot fix either by trying harder — it keeps taking messages that all fail the same way. A short
 * backoff rides out a blip; anything longer exits the worker, so Kubernetes restarts the pod on a
 * fresh connection and paces further attempts through its own restart backoff.
 *
 * Services outside the cluster are left alone: restarting does not bring them back, and taking every
 * worker down because one carrier is unreachable costs more than it saves. RejectDelayListener
 * delays those messages instead.
 */
final class WorkerBackoffListener implements EventSubscriberInterface
{
    private const int START_MS = 250;
    private const int MAX_FAILURES = 5;

    private int $failures = 0;

    /**
     * @param list<string> $internalHostMarkers substrings that mark a request host as cluster-internal;
     *                                          the default is the Kubernetes service DNS shape
     *                                          `<service>.<namespace>.svc.<cluster-domain>`
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $internalHostMarkers = ['.svc.'],
    ) {}

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->isInternalConnectivityFailure($event->getThrowable())) {
            return;
        }

        ++$this->failures;
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $this->failures = 0;
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (0 === $this->failures) {
            return;
        }

        if ($this->failures >= self::MAX_FAILURES) {
            $this->logger->critical('Stopping the worker after {failures} failures to reach a service inside the cluster', [
                'failures' => $this->failures,
            ]);

            throw new \RuntimeException(sprintf('Unable to reach a service inside the cluster, giving up after %d consecutive failures.', $this->failures));
        }

        $delayMs = self::START_MS << ($this->failures - 1);
        // Without jitter every worker backs off in lockstep and they all hit the failing service again
        // at the same moment, which is the spike we are trying to get rid of.
        usleep(random_int(intdiv($delayMs, 2), $delayMs) * 1000);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    private function isInternalConnectivityFailure(\Throwable $exception): bool
    {
        if ($exception instanceof HandlerFailedException) {
            foreach ($exception->getWrappedExceptions(null, true) as $wrappedException) {
                if ($this->isInternalConnectivityFailure($wrappedException)) {
                    return true;
                }
            }
        }

        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof NetworkExceptionInterface && $this->isInternalHost($exception->getRequest()->getUri()->getHost())) {
            return true;
        }

        $previousException = $exception->getPrevious();

        return null !== $previousException && $this->isInternalConnectivityFailure($previousException);
    }

    private function isInternalHost(string $host): bool
    {
        foreach ($this->internalHostMarkers as $internalHostMarker) {
            if (str_contains($host, $internalHostMarker)) {
                return true;
            }
        }

        return false;
    }
}
