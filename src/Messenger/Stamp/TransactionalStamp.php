<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class TransactionalStamp implements StampInterface
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly ?string $entityManagerName = null,
    ) {}
}
