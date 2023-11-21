<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Etrias\PhpToolkit\Cache\CacheInfo;
use Etrias\PhpToolkit\Cache\CacheInfoProvider;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Envelope;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class MessageCache implements TagAwareCacheInterface
{
    public function __construct(
        #[Target(name: 'messenger.cache')]
        private readonly TagAwareCacheInterface $cache,
        private readonly CacheInfoProvider $cacheInfoProvider,
    ) {}

    public function info(Envelope $envelope): ?CacheInfo
    {
        return $this->cacheInfoProvider->get($envelope->getMessage());
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->cache->get($key, $callback, $beta, $metadata);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function invalidateTags(array $tags): bool
    {
        return $this->cache->invalidateTags($tags);
    }
}
