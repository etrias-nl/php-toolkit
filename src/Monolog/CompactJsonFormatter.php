<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog;

use Monolog\Formatter\JsonFormatter;

/**
 * @internal
 */
final class CompactJsonFormatter extends JsonFormatter
{
    private bool $inList = false;

    public function __construct(
        string $basePath = '',
    ) {
        parent::__construct(self::BATCH_MODE_NEWLINES, true, false, true);

        $this->setBasePath($basePath);
    }

    protected function normalize(mixed $data, int $depth = 0): mixed
    {
        if (!$this->inList && \is_array($data) && $data && !array_filter(array_keys($data), static fn (mixed $key): bool => !is_numeric($key))) {
            $this->inList = true;

            try {
                return $this->toJson(parent::normalize($data, $depth));
            } finally {
                $this->inList = false;
            }
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
