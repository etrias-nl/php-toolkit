<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog\Processor;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

#[AsMonologProcessor]
final class CliRunIdProcessor implements EventSubscriberInterface
{
    private ?string $runId = null;

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null !== $this->runId) {
            $record->extra['cli_run_id'] = $this->runId;
        }

        return $record;
    }

    public function onCommand(): void
    {
        $this->runId = uniqid('', true);
    }

    public function onTerminate(): void
    {
        $this->runId = null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleCommandEvent::class => 'onCommand',
            ConsoleTerminateEvent::class => 'onTerminate',
        ];
    }
}
