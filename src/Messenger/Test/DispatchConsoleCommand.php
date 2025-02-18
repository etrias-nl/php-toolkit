<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Test;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Dumper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand(name: 'messenger-test:dispatch')]
final class DispatchConsoleCommand extends Command
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
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, '', 0)
            ->addOption('failure', null, InputOption::VALUE_NONE)
            ->addOption('nest', null, InputOption::VALUE_NONE)
            ->addOption('nest-failure', null, InputOption::VALUE_NONE)
            ->addOption('nest-sync', null, InputOption::VALUE_NONE)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, '', 1)
            ->addOption('class', null, InputOption::VALUE_REQUIRED)
            ->addArgument('payload', InputArgument::OPTIONAL)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dumper = new Dumper($output);

        if (null !== $payload = $input->getArgument('payload')) {
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
            }
        }
        if (null !== ($class = $input->getOption('class')) && !class_exists($class)) {
            throw new \InvalidArgumentException('Class not found: '.$class);
        }

        for ($i = 1; $i <= (int) $input->getOption('batch'); ++$i) {
            $output->writeln('Dispatching message #'.$i);
            if (null === $class) {
                $message = new DummyMessage(
                    $payload ?? 'Batch message #'.$i,
                    (float) $input->getOption('sleep'),
                    $input->getOption('failure'),
                    $input->getOption('nest'),
                    $input->getOption('nest-failure'),
                    $input->getOption('nest-sync'),
                );
            } else {
                try {
                    // @todo list => positional ctor args, map => hydrate property values
                    $message = \is_array($payload) ? new $class(...$payload) : new $class($payload);
                } catch (\Throwable $e) {
                    $io->error($e->getMessage());

                    return 1;
                }
                $io->writeln($dumper($message));
                if (!$io->confirm('Dispatch?', false)) {
                    continue;
                }
            }

            $stamps = $input->getOption('sync') ? [new TransportNamesStamp('sync')] : [];

            $this->messageBus->dispatch($message, $stamps);
        }

        return 0;
    }
}
