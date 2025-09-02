<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * @see https://stackoverflow.com/a/77468772
 */
final class DockerHandler extends AbstractProcessingHandler
{
    private const EXCLUDED_DEPRECATION_LOGS = [
        // @doctrine/orm:2
        '~The "Symfony\\\Component\\\HttpKernel\\\DependencyInjection\\\Extension" class is considered internal~i',
        // @doctrine/orm:3.5
        '~Access to ClassMetadata::\$reflFields is deprecated~i',
    ];

    private readonly string $command;

    /**
     * @var null|false|resource
     */
    private mixed $resource = null;

    public function __construct(
        Level $level = Level::Debug,
        bool $bubble = true,
        int $processId = 1,
        int $fileDescriptor = 2,
        string $basePath = '/app',
    ) {
        parent::__construct($level, $bubble);

        $this->command = \sprintf('cat - >> /proc/%d/fd/%d', $processId, $fileDescriptor);

        $this->pushProcessor(new PsrLogMessageProcessor());
        $this->setFormatter(new CompactJsonFormatter($basePath));
    }

    public function close(): void
    {
        if ($this->resource) {
            pclose($this->resource);
        }
    }

    protected function write(LogRecord $record): void
    {
        if ('deprecation' === $record->channel) {
            foreach (self::EXCLUDED_DEPRECATION_LOGS as $excludedDeprecationLog) {
                if (preg_match($excludedDeprecationLog, $record->message)) {
                    return;
                }
            }
        }

        $this->resource ??= popen($this->command, 'w');

        if ($this->resource) {
            @fwrite($this->resource, (string) $record->formatted);
        }
    }
}
