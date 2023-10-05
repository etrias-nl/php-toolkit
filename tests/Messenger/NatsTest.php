<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
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

        $transport = $factory->createTransport('nats://foo?stream=bar', [], $this->createMock(SerializerInterface::class));

        self::assertInstanceOf(NatsTransport::class, $transport);

        self::expectException(\RuntimeException::class);

        $factory->createTransport('nats://foo?streamm=bar', [], $this->createMock(SerializerInterface::class));
    }

    public function testTransport(): void
    {
        $transport = (new NatsTransportFactory())->createTransport('nats://nats?stream=test'.time(), [], new PhpSerializer());

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
    }
}
