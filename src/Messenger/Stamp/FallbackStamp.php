<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

final class FallbackStamp implements StampInterface
{
    public function __construct(
        public readonly \DateTimeImmutable $sendAt,
    ) {}
}
