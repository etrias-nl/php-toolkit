<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Console\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Tools\Console\Command\DoctrineCommand;
use Doctrine\Persistence\ManagerRegistry;
use Etrias\PhpToolkit\Console\LongRunningCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CommandLongRunningListener implements EventSubscriberInterface
{
    /**
     * @see DoctrineConnectionMiddleware::__construct()
     */
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly int $doctrineWaitTimeout = 28800,
        private readonly int $doctrineMaxExecutionTime = 3600,
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

        if ($command instanceof LongRunningCommand || $command instanceof DoctrineCommand) {
            $this->setLimits();
        }
    }

    /**
     * @see DoctrineConnectionMiddleware::setLimits()
     */
    private function setLimits(): void
    {
        /** @var Connection $connection */
        foreach ($this->managerRegistry->getConnections() as $connection) {
            foreach ([
                'SET SESSION wait_timeout = '.$this->doctrineWaitTimeout,
                'SET SESSION interactive_timeout = '.$this->doctrineWaitTimeout,
                'SET SESSION max_execution_time = '.($this->doctrineMaxExecutionTime * 1000),
            ] as $query) {
                $connection->executeQuery($query);
            }
        }
    }
}
