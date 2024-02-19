<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class DeduplicateStamp implements StampInterface
{
    public function __construct(
        public bool $enabled = true,
    ) {}
}
