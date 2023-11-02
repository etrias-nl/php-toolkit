<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

final class MessageMonitor
{
    public function __construct(
        private readonly MessageMap $messageMap,
    ) {}
}
