<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Config;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'config-compile:full')]
final class CompileFullCommand extends Command
{
    public function __construct(
        #[Autowire(param: 'kernel.bundles')]
        private array $bundles,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('output', InputArgument::REQUIRED)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputPath = (string) $input->getArgument('output');
        $fs = new Filesystem();
        $fs->remove($outputPath);
        $fs->touch($outputPath);
        $fs->appendToFile($outputPath, "# THIS FILE IS AUTO GENERATED. DO NOT CHANGE!\n");

        $bundles = array_keys($this->bundles);
        sort($bundles);

        foreach ($bundles as $bundle) {
            $bundleInput = new ArrayInput([
                'command' => 'debug:config',
                'name' => $bundle,
                '--format' => 'yaml',
            ]);
            $bundleInput->setInteractive(false);
            $bundleOutput = new BufferedOutput();

            try {
                $this->getApplication()->doRun($bundleInput, $bundleOutput);
            } catch (\Exception $e) {
                $fs->appendToFile($outputPath, "\n# {$bundle}\n# {$e->getMessage()}\n");

                continue;
            }

            $fs->appendToFile($outputPath, "\n# {$bundle}\n".trim($bundleOutput->fetch())."\n");
        }

        return 0;
    }
}
