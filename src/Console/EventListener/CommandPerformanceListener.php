<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Console\EventListener;

use Etrias\PhpToolkit\Performance\Benchmark;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

final class CommandPerformanceListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Benchmark $benchmark,
    ) {}

    public function startBench(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if ($command instanceof ConsumeMessagesCommand) {
            return;
        }

        $this->benchmark->start($command?->getName() ?? $event::class);
    }

    public function stopBench(): void
    {
        if ($this->benchmark->isStarted()) {
            $this->benchmark->stop();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleCommandEvent::class => ['startBench', -1024],
            ConsoleTerminateEvent::class => ['stopBench', 1024],
        ];
    }
}
