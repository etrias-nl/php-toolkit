<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Console\EventListener;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Uid\Uuid;

#[AsMonologProcessor]
final class CommandLogListener implements EventSubscriberInterface
{
    public const ENV_TRACE_ID = 'LOG_TRACE_ID';

    private ?Command $currentCommand = null;
    private ?string $runId = null;

    /**
     * @param iterable<array-key, callable(LogRecord, Command): LogRecord> $extraCliProcessors
     */
    public function __construct(
        #[AutowireIterator('cli.log_processor')]
        private readonly iterable $extraCliProcessors = [],
        #[Autowire(env: self::ENV_TRACE_ID)]
        private readonly ?string $traceId = null,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null === $this->currentCommand) {
            return $record;
        }

        $record->extra['cli']['command'] = $this->currentCommand->getName();
        $record->extra['cli']['run_id'] = $this->runId;

        if (null !== $this->traceId) {
            $record->extra['cli']['trace_id'] = $this->traceId;
        }

        foreach ($this->extraCliProcessors as $processor) {
            $record = $processor($record, $this->currentCommand);
        }

        return $record;
    }

    public function enterContext(ConsoleCommandEvent $event): void
    {
        $this->currentCommand = $event->getCommand();
        $this->runId = Uuid::v7()->toBase58();
    }

    public function leaveContext(): void
    {
        $this->currentCommand = null;
        $this->runId = null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleCommandEvent::class => ['enterContext', 1024],
            ConsoleTerminateEvent::class => ['leaveContext', -1024],
        ];
    }
}
