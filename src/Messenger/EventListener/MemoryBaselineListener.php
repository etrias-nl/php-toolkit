<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\EventListener;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

final class MemoryBaselineListener implements EventSubscriberInterface
{
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
        $event->getOutput()->writeln((string) (
            (int) trim(file_get_contents('/sys/fs/cgroup/memory.current') ?: '0')
            + (int) trim(file_get_contents('/sys/fs/cgroup/memory.swap.current') ?: '0')
            + (int) trim(file_get_contents('/sys/fs/cgroup/memory.zswap.current') ?: '0')
        ));
    }
}
