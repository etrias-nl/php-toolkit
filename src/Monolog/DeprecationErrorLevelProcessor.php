<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog;

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('monolog.processor', ['channel' => 'deprecation'])]
final class DeprecationErrorLevelProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        if ($record->level->isLowerThan(Level::Error)) {
            return $record->with(level: Level::Error);
        }

        return $record;
    }
}
