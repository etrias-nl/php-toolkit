<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CacheInfo
{
    public function __construct(
        public readonly string $key,
        public readonly null|\DateInterval|\DateTimeInterface|int $ttl = null,
        public readonly array $tags = [],
    ) {}

    public function toItem(CacheItemPoolInterface $cache): CacheItemInterface
    {
        $item = $cache->getItem($this->key);

        if ($this->ttl instanceof \DateTimeInterface) {
            $item->expiresAt($this->ttl);
        } else {
            $item->expiresAfter($this->ttl);
        }

        if ($this->tags) {
            if (!$item instanceof ItemInterface) {
                throw new \LogicException('Can only tag cache items of type '.ItemInterface::class);
            }

            $item->tag($this->tags);
        }

        return $item;
    }
}
