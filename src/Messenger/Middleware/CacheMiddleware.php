<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Cache\CacheInfoProvider;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class CacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        #[Target(name: 'messenger.cache')]
        private readonly TagAwareCacheInterface $cache,
        private readonly CacheInfoProvider $cacheInfoProvider,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (null === $info = $this->cacheInfoProvider->get($envelope->getMessage())) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $this->cache->get($info->key, static function (ItemInterface $item) use ($info, $envelope, $stack): Envelope {
            $item->tag($info->tags);

            if ($info->ttl instanceof \DateTimeInterface) {
                $item->expiresAt($info->ttl);
            } else {
                $item->expiresAfter($info->ttl);
            }

            return $stack->next()->handle($envelope, $stack);
        });
    }
}
