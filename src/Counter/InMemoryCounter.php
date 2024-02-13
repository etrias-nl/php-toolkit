<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Counter;

final class InMemoryCounter implements Counter
{
    private array $counts = [];

    public function delta(string $key, int $count): int
    {
        return $this->counts[$key] = ($this->counts[$key] ?? 0) + $count;
    }

    public function clear(array|string $key): void
    {
        foreach ((array) $key as $k) {
            unset($this->counts[$k]);
        }
    }

    public function get(string $key): ?int
    {
        return $this->counts[$key] ?? null;
    }

    public function keys(string $prefix = ''): array
    {
        $keys = [];
        foreach ($this->counts as $key => $_) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }
}
