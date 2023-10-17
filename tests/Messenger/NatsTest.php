<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Uid\Uuid;

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

        $transport = $factory->createTransport('nats://foo?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());

        self::assertInstanceOf(NatsTransport::class, $transport);

        self::expectException(\RuntimeException::class);

        $factory->createTransport('nats://foo?streamm=bar', [], new PhpSerializer());
    }

    public function testServiceUnavailable(): void
    {
        $transport = (new NatsTransportFactory())->createTransport('nats://foobar?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());

        self::expectException(TransportException::class);

        $transport->get();
    }

    public function testTransport(): void
    {
        $transport = (new NatsTransportFactory())->createTransport('nats://nats?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());

        $transport->setup();

        self::assertSame(0, $transport->getMessageCount());

        $message = (object) ['test' => true];
        $prevMessageId = Uuid::v4()->toRfc4122();
        $envelope = $transport->send(Envelope::wrap($message, [new TransportMessageIdStamp($prevMessageId)]));
        $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();

        self::assertSame(1, $transport->getMessageCount());
        self::assertSame($message, $envelope->getMessage());
        self::assertTrue(\is_string($messageId) && Uuid::isValid($messageId));
        self::assertNotSame($prevMessageId, $messageId);

        $ackedEnvelopes = $transport->get();

        self::assertIsArray($ackedEnvelopes);
        self::assertCount(1, $ackedEnvelopes);
        self::assertSame(0, $transport->getMessageCount());

        $ackedEnvelope = $ackedEnvelopes[0];

        self::assertSame((array) $message, (array) $ackedEnvelope->getMessage());
        self::assertSame($messageId, $ackedEnvelope->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame([], $transport->get());
    }
}
