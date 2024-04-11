<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final class RejectDelayStamp implements NonSendableStampInterface
{
    public function __construct(
        public readonly int $milliseconds,
    ) {}
}
