<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache;

final class CacheInfo
{
    public readonly string $key;

    /**
     * @param (\Closure(mixed):(string|string[])|string)[] $tags
     */
    public function __construct(
        array|object|string $key,
        public readonly \DateInterval|\DateTimeInterface|int|null $ttl = null,
        public readonly array $tags = [],
    ) {
        $this->key = \is_string($key) ? $key : hash('xxh128', serialize($key));
    }

    public static function makeTag(int|string|null $segment, int|string|null ...$segments): string
    {
        return implode('_', array_filter(
            array_map(static fn (int|string|null $segment): string => str_replace('\\', '-', (string) $segment), [$segment, ...$segments]),
            static fn (string $segment): bool => '' !== $segment
        ));
    }
}
