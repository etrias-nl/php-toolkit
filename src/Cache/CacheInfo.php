<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache;

final class CacheInfo
{
    public readonly string $key;

    /**
     * @param (\Closure(mixed):string|string)[] $tags
     */
    public function __construct(
        array|object|string $key,
        public readonly null|\DateInterval|\DateTimeInterface|int $ttl = null,
        public readonly array $tags = [],
    ) {
        $this->key = \is_string($key) ? $key : hash('xxh128', serialize($key));
    }

    public static function makeTag(int|string $segment, int|string ...$segments): string
    {
        return implode('_', array_map(static fn (int|string $segment): string => str_replace('\\', '-', (string) $segment), [$segment, ...$segments]));
    }
}
