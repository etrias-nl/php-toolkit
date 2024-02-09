<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Monolog;

use Etrias\PhpToolkit\Monolog\CompactJsonFormatter;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CompactJsonFormatterTest extends TestCase
{
    public function testFormat(): void
    {
        $formatter = new CompactJsonFormatter();

        self::assertSame(['foo' => 'bar', 123], $formatter->normalizeValue(['foo' => 'bar', 123]));
        self::assertSame('[123,456]', $formatter->normalizeValue([123, 456]));
        self::assertSame('{"999":123,"1000":{"foo":["bar"]}}', $formatter->normalizeValue([999 => 123, ['foo' => ['bar']]]));
    }

    public function testFormatException(): void
    {
        $formatter = new CompactJsonFormatter();
        $exception = new \RuntimeException('foo', 213, $prev = new \LogicException('bar'));
        $formatted = $formatter->normalizeValue($exception);

        self::assertIsArray($formatted);
        self::assertSame('RuntimeException', $formatted['class']);
        self::assertSame('foo', $formatted['message']);
        self::assertSame(213, $formatted['code']);
        self::assertIsString($formatted['file']);
        self::assertIsString($formatted['trace']);

        self::assertIsArray($formatted['previous']);
        self::assertSame('LogicException', $formatted['previous']['class']);
        self::assertSame('bar', $formatted['previous']['message']);
        self::assertSame(0, $formatted['previous']['code']);
        self::assertIsString($formatted['previous']['file']);
        self::assertIsString($formatted['previous']['trace']);
    }
}
