<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Counter;

interface Counter
{
    public function delta(string $key, int $count): int;

    public function clear(string $key): void;

    /**
     * @return array<string, int>
     */
    public function values(string $prefix = ''): array;
}
