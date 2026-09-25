<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Monolog;

use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\ReducedLogStamp;
use Monolog\Handler\Handler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Swallows records below WARNING that belong to a message class marked with ReducedLogStamp.
 * The class is taken from the Messenger log context, or from the LogMiddleware processor while handling.
 */
final class ReducedLogHandler extends Handler
{
    public function __construct(
        private readonly MessageMap $messageMap,
    ) {}

    public function isHandling(LogRecord $record): bool
    {
        return $record->level->isLowerThan(Level::Warning);
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        $message = $record->context['class'] ?? $record->context['message'] ?? $record->extra['messenger']['message'] ?? null;

        return \is_string($message) && $this->messageMap->hasDefaultStamp($message, ReducedLogStamp::class);
    }
}
