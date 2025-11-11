<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache\Console;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'test:cache:adapter', description: 'Test the configured cache adapter functionality')]
final class TestAdapterCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'cache.test')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $key = uniqid('test_key_');
        $value = ['foo' => 'bar', 'time' => time()];

        $io->info('Testing cache adapter with key: '.$key);

        $item = $this->cache->getItem($key);
        $item->set($value);

        $this->cache->save($item);

        $fetched = $this->cache->getItem($key);
        if (!$fetched->isHit()) {
            throw new \RuntimeException('Cache item was not saved properly (cache miss)');
        }
        if ($fetched->get() !== $value) {
            throw new \RuntimeException('Cache item value mismatch');
        }
        $io->info('Set/Get test passed');

        if (!$this->cache->hasItem($key)) {
            throw new \RuntimeException('hasItem() returned false after saving');
        }
        $io->info('hasItem() test passed');

        $this->cache->deleteItem($key);
        if ($this->cache->hasItem($key)) {
            throw new \RuntimeException('deleteItem() failed; key still exists');
        }
        $io->info('Delete test passed');

        $io->success('All cache tests passed successfully.');

        return 0;
    }
}
