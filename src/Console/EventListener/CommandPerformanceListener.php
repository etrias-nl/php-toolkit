<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Console\EventListener;

use Etrias\PhpToolkit\Performance\Benchmark;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CommandPerformanceListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Benchmark $benchmark,
    ) {}

    public function startBench(ConsoleCommandEvent $event): void
    {
        $this->benchmark->start($event->getCommand()?->getName() ?? $event::class);
    }

    public function stopBench(): void
    {
        $this->benchmark->stop();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleCommandEvent::class => ['startBench', 1023],
            ConsoleErrorEvent::class => 'stopBench',
            ConsoleTerminateEvent::class => 'stopBench',
        ];
    }
}
