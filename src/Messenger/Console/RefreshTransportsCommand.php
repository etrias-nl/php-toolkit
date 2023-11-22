<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;

#[AsCommand(name: 'messenger:refresh-transports', description: 'Refresh the required infrastructure for the transport')]
final class RefreshTransportsCommand extends Command
{
    public function __construct(
        #[TaggedIterator(tag: 'messenger.receiver', indexAttribute: 'alias')]
        private readonly iterable $receivers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Perform a dry run (disables writing changes)')
            ->addArgument('transports', InputArgument::IS_ARRAY, 'Name of the transports to refresh', null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $transports = $input->getArgument('transports');

        foreach ($this->receivers as $alias => $receiver) {
            if ($transports && !\in_array($alias, $transports, true)) {
                continue;
            }

            $io->title($alias);

            if ($receiver instanceof MessageCountAwareInterface) {
                $io->info('Current number of messages: '.$receiver->getMessageCount());
            }

            if (!$dryRun && !$io->confirm('Are you sure?', false)) {
                continue;
            }

            if ($receiver instanceof SetupableTransportInterface && 2 === (new \ReflectionMethod($receiver, 'setup'))->getNumberOfParameters()) {
                /** @psalm-suppress TooManyArguments */
                $receiver->setup(true, $dryRun);
                $io->success('Transport was refreshed successfully');
            } else {
                $io->note('Transport does not support setup');
            }
        }

        if ($dryRun) {
            $io->note('This was a dry run, nothing is changed!');
        }

        return Command::SUCCESS;
    }
}
