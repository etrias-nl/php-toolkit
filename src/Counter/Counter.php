<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Counter;

interface Counter
{
    public function delta(string $key, int $count): int;

    /**
     * @param string|string[] $key
     */
    public function clear(array|string $key): void;

    public function get(string $key): ?int;

    /**
     * @return string[]
     */
    public function keys(string $prefix = ''): array;
}
