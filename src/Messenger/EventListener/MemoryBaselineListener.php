<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

final class MemoryBaselineListener implements EventSubscriberInterface
{
    public function __construct(
        #[Target(name: 'messenger.logger')]
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if (!$command instanceof ConsumeMessagesCommand) {
            return;
        }

        if (!$event->getInput()->hasParameterOption('--memory-baseline', true)) {
            return;
        }

        $event->disableCommand();

        $memory = (int) trim(file_get_contents('/sys/fs/cgroup/memory.current') ?: '0');
        $memoryMiB = $memory / 1024 / 1024;

        $this->logger->info(\sprintf('Worker baseline memory (%.2F MiB)', $memoryMiB), [
            'memory_baseline' => $memoryMiB,
        ]);

        $event->getOutput()->writeln((string) $memory);
    }
}
