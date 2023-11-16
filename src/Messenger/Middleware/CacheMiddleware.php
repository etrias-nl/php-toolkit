<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Cache\CacheInfoProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        #[Target(name: 'messenger.cache')]
        private readonly CacheItemPoolInterface $cache,
        private readonly CacheInfoProvider $cacheInfoProvider,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $result = $stack->next()->handle($envelope, $stack);

        if (null === $cacheInfo = $this->cacheInfoProvider->get($envelope->getMessage())) {
            return $result;
        }

        $cacheItem = $cacheInfo->toItem($this->cache);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $cacheItem->set($result);
        $this->cache->save($cacheItem);

        return $result;
    }
}
