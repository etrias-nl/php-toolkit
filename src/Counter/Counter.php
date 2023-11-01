<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Counter;

interface Counter
{
    public function increment(string $key, int $step = 1): int;

    public function decrement(string $key, int $step = 1): int;

    public function get(string $key): int;

    public function reset(string $key): void;
}
