<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache;

final class CacheInfo
{
    public function __construct(
        public readonly string $key,
        public readonly int|float|null $ttl = null,
        public readonly array $tags = [],
    ) {
    }
}
