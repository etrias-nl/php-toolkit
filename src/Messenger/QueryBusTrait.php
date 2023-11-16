<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger;

use Symfony\Component\Messenger\HandleTrait;

trait QueryBusTrait
{
    use HandleTrait {
        HandleTrait::handle as query;
    }
}
