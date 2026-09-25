<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Suppresses the informational log records of a message class, see ReducedLogHandler.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ReducedLogStamp implements StampInterface {}
