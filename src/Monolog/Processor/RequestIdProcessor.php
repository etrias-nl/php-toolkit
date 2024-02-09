<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog\Processor;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsMonologProcessor]
final class RequestIdProcessor
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
