<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;

final class DockerHandler extends AbstractProcessingHandler
{
    private readonly string $command;

    /**
     * @var null|resource
     */
    private mixed $resource = null;

    public function __construct(
        Level $level = Level::Debug,
        bool $bubble = true,
        int $processId = 1,
        int $fileDescriptor = 2,
    ) {
        parent::__construct($level, $bubble);

        $this->command = sprintf('cat - >> /proc/%d/fd/%d', $processId, $fileDescriptor);

        $this->pushProcessor(new PsrLogMessageProcessor());
        $this->setFormatter(new CompactJsonFormatter());
    }

    public function close(): void
    {
        if (null !== $this->resource) {
            pclose($this->resource);
        }
    }

    protected function write(LogRecord $record): void
    {
        $this->resource ??= popen($this->command, 'w');

        @fwrite($this->resource, (string) $record->formatted);
    }
}
