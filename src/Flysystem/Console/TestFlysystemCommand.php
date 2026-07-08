<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Flysystem\Console;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'test:flysystem')]
final class TestFlysystemCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'test.storage')]
        private readonly ?FilesystemOperator $storage = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storage = $this->storage;
        if (null === $storage) {
            $io->error('Storage "test.storage" is not configured.');

            return 1;
        }

        $file = uniqid('test_file_').'.txt';
        $renamedFile = $file.'.renamed';
        $contents = 'test '.time();

        $io->info('Testing filesystem with file: '.$file);

        $storage->write($file, $contents);
        $io->info('Write test passed');

        if ($contents !== $storage->read($file)) {
            throw new \RuntimeException('read() returned other contents than written');
        }
        $io->info('Read test passed');

        $files = $this->listFiles($storage);
        $io->listing($files);
        if (!\in_array($file, $files, true)) {
            throw new \RuntimeException('listContents() is missing the written file');
        }
        $io->info('List test passed');

        $storage->move($file, $renamedFile);
        $files = $this->listFiles($storage);
        $io->listing($files);
        if (!\in_array($renamedFile, $files, true) || \in_array($file, $files, true)) {
            throw new \RuntimeException('move() failed; listing does not reflect the renamed file');
        }
        $io->info('Rename test passed');

        $storage->delete($renamedFile);
        if ($storage->fileExists($renamedFile)) {
            throw new \RuntimeException('delete() failed; file still exists');
        }
        $io->info('Delete test passed');

        $io->success('All filesystem tests passed successfully.');

        return 0;
    }

    /**
     * @return string[]
     */
    private function listFiles(FilesystemOperator $storage): array
    {
        return $storage->listContents('')
            ->filter(static fn (StorageAttributes $attributes): bool => $attributes->isFile())
            ->map(static fn (StorageAttributes $attributes): string => $attributes->path())
            ->toArray()
        ;
    }
}
