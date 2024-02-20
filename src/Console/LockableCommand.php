<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Console;

use Symfony\Component\Console\Input\InputInterface;

interface LockableCommand
{
    public function shouldLock(InputInterface $input): bool;

    public function getLockResource(InputInterface $input): string;

    public function getLockTtl(InputInterface $input): ?float;
}
