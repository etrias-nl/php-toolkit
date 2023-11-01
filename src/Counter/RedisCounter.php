<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Counter;

use Predis\ClientInterface;

final class RedisCounter implements Counter
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly string $prefix = 'counter:',
    ) {}

    public function increment(string $key, int $step = 1): int
    {
        return $this->redis->incrby($this->prefix.$key, $step);
    }

    public function decrement(string $key, int $step = 1): int
    {
        return $this->redis->decrby($this->prefix.$key, $step);
    }

    public function get(string $key): int
    {
        return (int) ($this->redis->get($this->prefix.$key) ?? 0);
    }

    public function reset(string $key): void
    {
        $this->redis->del($this->prefix.$key);
    }
}
