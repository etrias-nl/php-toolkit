<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Controller\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(name: 'test:controllers')]
final class TestControllersCommand extends Command
{
    public function __construct(
        #[AutowireIterator('controller.service_arguments')]
        private readonly \Traversable $controllers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = iterator_count($this->controllers);

        $io->success('Found '.$count.' controllers');

        return 0;
    }
}
