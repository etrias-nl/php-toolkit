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

    public function clear(array|string $key): void
    {
        $this->redis->del(array_map(fn (string $k): string => $this->prefix.$k, (array) $key));
    }

    public function set(string $key, int $count): void
    {
        $this->redis->set($this->prefix.$key, $count);
    }

    public function get(string $key): ?int
    {
        return null === ($count = $this->redis->get($this->prefix.$key)) ? null : (int) $count;
    }

    public function keys(string $prefix = ''): array
    {
        $keys = [];

        foreach ($this->redis->keys($this->prefix.$prefix.'*') as $key) {
            $keys[] = substr($key, \strlen($this->prefix));
        }

        return $keys;
    }
}
