<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache;

final class CacheInfo
{
    public readonly string $key;

    /**
     * @param string[] $tags
     */
    public function __construct(
        array|object|string $key,
        public readonly null|\DateInterval|\DateTimeInterface|int $ttl = null,
        public readonly array $tags = [],
    ) {
        $this->key = \is_string($key) ? $key : hash('xxh128', serialize($key));
    }

    public static function makeTag(string $segment, string ...$segments): string
    {
        return implode('_', array_map(static fn (string $segment): string => str_replace('\\', '-', $segment), [$segment, ...$segments]));
    }
}
