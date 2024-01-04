<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Cache\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class WithCacheInfoProvider
{
    public function __construct(
        public readonly string $name,
    ) {}
}
