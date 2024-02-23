<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Test;

use Etrias\PhpToolkit\Messenger\Attribute\WithTransport;
use Etrias\PhpToolkit\Messenger\Stamp\DeduplicateStamp;
use Etrias\PhpToolkit\Messenger\Stamp\TransactionalStamp;

#[WithTransport('test')]
#[DeduplicateStamp(true)]
#[TransactionalStamp(false)]
class DummyCommandMessage
{
    public function __construct(
        public readonly mixed $payload,
        public readonly int $sleep = 0,
        public readonly bool $failure = false,
        public readonly bool $nest = false,
        public readonly bool $nestFailure = false,
        public readonly bool $nestSync = false,
    ) {}
}
