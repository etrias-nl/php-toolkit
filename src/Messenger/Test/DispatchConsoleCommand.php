<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Test;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand(name: 'messenger-test:dispatch')]
class DispatchConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sync', null, InputOption::VALUE_NONE)
            ->addOption('failure', null, InputOption::VALUE_NONE)
            ->addOption('nest', null, InputOption::VALUE_NONE)
            ->addOption('nest-failure', null, InputOption::VALUE_NONE)
            ->addOption('nest-sync', null, InputOption::VALUE_NONE)
            ->addArgument('payload', InputArgument::OPTIONAL, '', 'Hello World!')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $payload = $input->getArgument('payload');

        try {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
        }

        $message = new DummyCommandMessage($payload, $input->getOption('failure'), $input->getOption('nest'), $input->getOption('nest-failure'), $input->getOption('nest-sync'));
        $stamps = [];

        if ($input->getOption('sync')) {
            $stamps[] = new TransportNamesStamp('sync');
        }

        $this->messageBus->dispatch($message, $stamps);

        return 0;
    }
}
