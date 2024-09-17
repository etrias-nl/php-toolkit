<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

#[\Attribute(\Attribute::TARGET_CLASS)]
/**
 * @deprecated use entity manager's wrapInTransaction() in handlers instead
 */
final class TransactionalStamp implements StampInterface
{
    public function __construct(
        public bool $enabled = true,
    ) {}
}
