<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog\Processor;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

#[AsMonologProcessor]
final class RequestIdProcessor implements EventSubscriberInterface
{
    private const string REQUEST_ID_HEADER = 'X-Request-ID';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null !== $request = $this->requestStack->getCurrentRequest()) {
            $record->extra['request_id'] = $request->headers->get(self::REQUEST_ID_HEADER);
        }

        return $record;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ResponseEvent::class => ['onKernelResponse', 1024],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (null === $requestId = $event->getRequest()->headers->get(self::REQUEST_ID_HEADER)) {
            return;
        }

        $event->getResponse()->headers->set(self::REQUEST_ID_HEADER, $requestId);
    }
}
