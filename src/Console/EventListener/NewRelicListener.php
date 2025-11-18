<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Console\EventListener;

use Doctrine\Migrations\Tools\Console\Command\DoctrineCommand;
use Etrias\PhpToolkit\Console\LongRunningCommand;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

final class NewRelicListener implements EventSubscriberInterface
{
    private bool $transactionActive = false;

    public function startTransaction(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if (null === $command || $command instanceof LongRunningCommand || $command instanceof ConsumeMessagesCommand || $command instanceof DoctrineCommand) {
            if (!$this->transactionActive) {
                // @see https://docs.newrelic.com/docs/apm/agents/php-agent/troubleshooting/performance-issues-long-running-task/
                newrelic_ignore_transaction();
                newrelic_end_transaction();
            }

            return;
        }

        if ($this->transactionActive) {
            return;
        }

        newrelic_name_transaction($command->getName() ?? $command::class);
        newrelic_background_job();

        $this->transactionActive = true;
    }

    public function errorTransaction(ConsoleErrorEvent $event): void
    {
        if ($this->transactionActive) {
            newrelic_notice_error($event->getError());

            return;
        }

        newrelic_ignore_transaction();
        newrelic_end_transaction();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleCommandEvent::class => ['startTransaction', -2048],
            ConsoleErrorEvent::class => ['errorTransaction', 2048],
        ];
    }
}
