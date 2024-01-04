<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Etrias\PhpToolkit\Cache\CacheInfo;
use Etrias\PhpToolkit\Cache\CacheInfoProvider;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Envelope;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

final class MessageCache implements TagAwareCacheInterface
{
    /**
     * @param ServiceProviderInterface<CacheInfoProvider> $cacheInfoProviders
     */
    public function __construct(
        #[Target(name: 'messenger.cache')]
        private readonly TagAwareCacheInterface $cache,
        private readonly ServiceProviderInterface $cacheInfoProviders,
    ) {}

    public function info(Envelope $envelope): ?CacheInfo
    {
        $message = $envelope->getMessage();

        return $this->cacheInfoProviders->has($message::class) ? $this->cacheInfoProviders->get($message::class)->get($message) : null;
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
