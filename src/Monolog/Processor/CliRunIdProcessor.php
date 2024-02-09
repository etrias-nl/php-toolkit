<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog\Processor;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\Console\ConsoleEvents;
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

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->runId = uniqid('', true);
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $this->runId = null;
    }
}
