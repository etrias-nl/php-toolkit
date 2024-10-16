<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class DeduplicateStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly bool $enabled = true,
    ) {}
}
