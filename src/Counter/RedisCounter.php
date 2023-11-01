<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Counter;

use Predis\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RedisCounter implements Counter
{
    public function __construct(
        #[Autowire(service: 'cache.default_redis_provider')]
        private readonly ClientInterface $redis,
    ) {}

    public function delta(string $key, int $count): int
    {
        return $count > 0 ? $this->redis->incrby($key, $count) : $this->redis->decrby($key, abs($count));
    }

    public function get(string $key): int
    {
        return (int) ($this->redis->get($key) ?? 0);
    }

    public function clear(string $key): void
    {
        $this->redis->del($key);
    }
}
