<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\EventListener;

use Etrias\PhpToolkit\Messenger\Stamp\RejectDelayStamp;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class RejectDelayListener implements EventSubscriberInterface
{
    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (null === $delayMs = $this->getDelayMs($event->getThrowable())) {
            return;
        }

        $event->addStamps(new RejectDelayStamp($delayMs));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];
    }

    private function getDelayMs(\Throwable $exception): ?int
    {
        if ($exception instanceof HandlerFailedException) {
            foreach ($exception->getWrappedExceptions(null, true) as $wrappedException) {
                if (null !== $delayMs = $this->getDelayMs($wrappedException)) {
                    return $delayMs;
                }
            }
        }

        if ($exception instanceof ClientExceptionInterface && method_exists($exception, 'getResponse')) {
            $response = $exception->getResponse();

            if ($response instanceof ResponseInterface && Response::HTTP_TOO_MANY_REQUESTS === $response->getStatusCode()) {
                $retryAfter = (int) ($response->getHeader('retry-after')[0] ?? 0);

                if ($retryAfter > 0) {
                    return $retryAfter * 1000;
                }
            }
        }

        return null;
    }
}
