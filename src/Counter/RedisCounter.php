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
        private readonly string $prefix = 'counter:default:',
    ) {}

    public function delta(string $key, int $count): int
    {
        return $count > 0 ? $this->redis->incrby($this->prefix.$key, $count) : $this->redis->decrby($this->prefix.$key, abs($count));
    }

    public function get(string $key): int
    {
        return (int) ($this->redis->get($this->prefix.$key) ?? 0);
    }

    public function clear(string $key): void
    {
        $this->redis->del($this->prefix.$key);
    }
}
