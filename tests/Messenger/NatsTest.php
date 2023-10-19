<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\SentStamp;
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

        $message = (object) ['test1' => true];
        $prevMessageId = Uuid::v4()->toRfc4122();
        $envelope = $transport->send(Envelope::wrap($message, [new TransportMessageIdStamp($prevMessageId)]));
        $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();

        $transport->setup();

        self::assertSame(1, $transport->getMessageCount());
        self::assertSame($message, $envelope->getMessage());
        self::assertIsString($messageId);
        self::assertSame(32, \strlen($messageId));
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

    public function testDeduplication(): void
    {
        $transport = (new NatsTransportFactory([
            'sender_without_deduplication' => [
                \stdClass::class => [
                    'deduplicate' => false,
                ],
            ],
        ]))->createTransport('nats://nats?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        $messageId1 = $transport->send(Envelope::wrap((object) ['test_a' => true]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId2 = $transport->send(Envelope::wrap((object) ['test_a' => true], [new SentStamp(self::class, 'sender')]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId3 = $transport->send(Envelope::wrap((object) ['test_a' => true], [new SentStamp(self::class, 'sender_without_deduplication')]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId4 = $transport->send(Envelope::wrap((object) ['test_b' => true]))->last(TransportMessageIdStamp::class)?->getId();

        self::assertSame(3, $transport->getMessageCount());
        self::assertSame($messageId1, $messageId2);
        self::assertNotSame($messageId1, $messageId3);
        self::assertNotSame($messageId1, $messageId4);
        self::assertNotSame($messageId3, $messageId4);

        $ackedEnvelopes1 = $transport->get();
        $ackedEnvelopes2 = $transport->get();
        $ackedEnvelopes3 = $transport->get();

        self::assertIsArray($ackedEnvelopes1);
        self::assertCount(1, $ackedEnvelopes1);
        self::assertIsArray($ackedEnvelopes2);
        self::assertCount(1, $ackedEnvelopes2);
        self::assertIsArray($ackedEnvelopes3);
        self::assertCount(1, $ackedEnvelopes3);
        self::assertSame($messageId1, $ackedEnvelopes1[0]->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame($messageId3, $ackedEnvelopes2[0]->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame($messageId4, $ackedEnvelopes3[0]->last(TransportMessageIdStamp::class)?->getId());
    }
}
