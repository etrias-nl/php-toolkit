<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache;

interface CacheInfoProvider
{
    public function get(mixed $subject): ?CacheInfo;
}
