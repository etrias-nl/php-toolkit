<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog;

use Monolog\Formatter\JsonFormatter;

/**
 * @internal
 */
final class DockerFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(self::BATCH_MODE_NEWLINES, true, false, true);
    }

    protected function normalize(mixed $data, int $depth = 0): mixed
    {
        if (\is_array($data) && array_is_list($data)) {
            return $this->toJson($data, true);
        }

        return parent::normalize($data, $depth);
    }

    protected function normalizeException(\Throwable $e, int $depth = 0): array
    {
        $data = parent::normalizeException($e, $depth);

        if (\is_array($trace = $data['trace'] ?? null)) {
            $data['trace'] = implode("\n", $trace);
        }

        return $data;
    }
}
