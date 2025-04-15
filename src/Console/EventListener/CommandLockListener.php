<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Console\EventListener;

use Etrias\PhpToolkit\Console\LockableCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class CommandLockListener implements EventSubscriberInterface
{
    private ?LockInterface $lock = null;

    public function __construct(
        private readonly LockFactory $lockFactory,
    ) {}

    public function acquireLock(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if (!$command instanceof LockableCommand || !$command->shouldLock($event->getInput())) {
            return;
        }

        $this->lock = $this->lockFactory->createLock($command->getLockResource($event->getInput()), $command->getLockTtl($event->getInput()));

        if (!$this->lock->acquire()) {
            $event->disableCommand();
        }
    }

    public function releaseLock(ConsoleTerminateEvent $event): void
    {
        if (null === $this->lock) {
            return;
        }

        $this->lock->release();
        $this->lock = null;

        if (ConsoleCommandEvent::RETURN_CODE_DISABLED === $event->getExitCode()) {
            $event->setExitCode(Command::SUCCESS);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleCommandEvent::class => 'acquireLock',
            ConsoleTerminateEvent::class => 'releaseLock',
        ];
    }
}
