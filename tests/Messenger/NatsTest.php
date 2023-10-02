<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * @internal
 */
final class NatsTest extends TestCase
{
    public function testTransportFactory(): void
    {
        $factory = new NatsTransportFactory();

        self::assertTrue($factory->supports('nats://foo', []));
        self::assertFalse($factory->supports('natss://foo', []));

        $transport = $factory->createTransport('nats://foo?stream=bar', [], $this->createMock(SerializerInterface::class));

        self::assertInstanceOf(NatsTransport::class, $transport);

        self::expectException(\RuntimeException::class);

        $factory->createTransport('nats://foo?streamm=bar', [], $this->createMock(SerializerInterface::class));
    }
}
