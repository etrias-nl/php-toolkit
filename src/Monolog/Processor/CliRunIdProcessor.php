<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CliRunIdProcessor implements ProcessorInterface, EventSubscriberInterface
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
