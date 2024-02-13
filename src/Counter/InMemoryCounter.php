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

    public function clear(string $key): void
    {
        unset($this->counts[$key]);
    }

    public function values(string $prefix = ''): array
    {
        $counts = [];
        foreach ($this->counts as $key => $count) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $counts[substr($key, \strlen($prefix))] = $count;
        }

        return $counts;
    }
}
