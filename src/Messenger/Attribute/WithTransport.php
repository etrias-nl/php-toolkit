<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class WithTransport
{
    public function __construct(
        public readonly string $name,
        public readonly array $options = [],
    ) {}
}
