<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null !== $request = $this->requestStack->getCurrentRequest()) {
            $record->extra['request_id'] = $request->headers->get('X-Request-ID');
        }

        return $record;
    }
}
