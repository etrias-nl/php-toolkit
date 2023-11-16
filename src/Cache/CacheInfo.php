<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache;

final class CacheInfo
{
    public readonly string $key;

    public function __construct(
        array|object|string $key,
        public readonly null|\DateInterval|\DateTimeInterface|int $ttl = null,
        public readonly array $tags = [],
    ) {
        $this->key = \is_string($key) ? $key : hash('xxh128', serialize($key));
    }
}
