<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Counter\ArrayCounter;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransportFactory;
use Monolog\Logger;
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
        $factory = new NatsTransportFactory(new MessageMap([]), new ArrayCounter(), new Logger('test'));

        self::assertTrue($factory->supports('nats://foo', []));
        self::assertFalse($factory->supports('natss://foo', []));

        $transport = $factory->createTransport('nats://foo?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());

        self::assertInstanceOf(NatsTransport::class, $transport);

        self::expectException(\RuntimeException::class);

        $factory->createTransport('nats://foo?streamm=bar', [], new PhpSerializer());
    }

    public function testServiceUnavailable(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new ArrayCounter(), new Logger('test'));
        $transport = $factory->createTransport('nats://foobar?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());

        self::expectException(TransportException::class);

        $transport->get();
    }

    public function testTransport(): void
    {
        $counter = new ArrayCounter();
        $factory = new NatsTransportFactory(new MessageMap([]), $counter, new Logger('test'));
        $transport = $factory->createTransport('nats://nats?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        self::assertSame(0, $transport->getMessageCount());
        self::assertSame([], $transport->getMessageCounts());

        $message = (object) ['test1' => true];
        $prevMessageId = Uuid::v4()->toRfc4122();
        $envelope = $transport->send(Envelope::wrap($message, [new TransportMessageIdStamp($prevMessageId)]));
        $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();

        $transport->setup();

        self::assertSame(1, $transport->getMessageCount());
        self::assertSame([\stdClass::class => 1], $transport->getMessageCounts());
        self::assertStringMatchesFormat('{"%a:stdClass":1}', json_encode($counter->values(), JSON_THROW_ON_ERROR));
        self::assertSame($message, $envelope->getMessage());
        self::assertTrue(\is_string($messageId) && 32 === \strlen($messageId));
        self::assertNotSame($messageId, $prevMessageId);

        $sentEnvelopes = $transport->get();

        self::assertIsArray($sentEnvelopes);
        self::assertCount(1, $sentEnvelopes);
        self::assertSame(1, $transport->getMessageCount());
        self::assertSame([\stdClass::class => 1], $transport->getMessageCounts());
        self::assertStringMatchesFormat('{"%a:stdClass":1}', json_encode($counter->values(), JSON_THROW_ON_ERROR));

        $ackedEnvelope = $sentEnvelopes[0];
        $transport->ack($ackedEnvelope);

        self::assertSame(0, $transport->getMessageCount());
        self::assertSame([], $transport->getMessageCounts());
        self::assertSame([], $counter->values());
        self::assertSame((array) $message, (array) $ackedEnvelope->getMessage());
        self::assertSame($messageId, $ackedEnvelope->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame([], $transport->get());
    }

    public function testDeduplication(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([
            'sender_without_deduplication' => [
                \stdClass::class => [
                    'deduplicate' => false,
                ],
            ],
        ]), new ArrayCounter(), new Logger('test'));
        $transport = $factory->createTransport('nats://nats?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());

        $messageId1 = $transport->send(Envelope::wrap((object) ['test_a' => true]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId2 = $transport->send(Envelope::wrap((object) ['test_a' => true], [new SentStamp(self::class, 'sender')]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId3 = $transport->send(Envelope::wrap((object) ['test_a' => true], [new SentStamp(self::class, 'sender_without_deduplication')]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId4 = $transport->send(Envelope::wrap((object) ['test_b' => true]))->last(TransportMessageIdStamp::class)?->getId();

        self::assertSame(3, $transport->getMessageCount());
        self::assertSame([\stdClass::class => 4], $transport->getMessageCounts());
        self::assertTrue(\is_string($messageId1) && 32 === \strlen($messageId1));
        self::assertSame($messageId1, $messageId2);
        self::assertTrue(\is_string($messageId3) && Uuid::isValid($messageId3));
        self::assertNotSame($messageId3, $messageId1);
        self::assertTrue(\is_string($messageId4) && 32 === \strlen($messageId4));
        self::assertNotSame($messageId4, $messageId1);
        self::assertNotSame($messageId4, $messageId3);

        $sentEnvelopes1 = $transport->get();

        self::assertIsArray($sentEnvelopes1);
        self::assertCount(1, $sentEnvelopes1);

        $ackedEnvelope1 = $sentEnvelopes1[0];
        $transport->ack($ackedEnvelope1);

        self::assertSame(2, $transport->getMessageCount());
        self::assertSame([\stdClass::class => 3], $transport->getMessageCounts());

        $sentEnvelopes2 = $transport->get();

        self::assertIsArray($sentEnvelopes2);
        self::assertCount(1, $sentEnvelopes2);

        $ackedEnvelope2 = $sentEnvelopes2[0];
        $transport->ack($ackedEnvelope2);

        self::assertSame(1, $transport->getMessageCount());
        self::assertSame([\stdClass::class => 2], $transport->getMessageCounts());

        $sentEnvelopes3 = $transport->get();

        self::assertIsArray($sentEnvelopes3);
        self::assertCount(1, $sentEnvelopes3);

        $ackedEnvelope3 = $sentEnvelopes3[0];
        $transport->ack($ackedEnvelope3);

        self::assertSame(0, $transport->getMessageCount());
        self::assertSame([], $transport->getMessageCounts());
        self::assertSame($messageId1, $ackedEnvelope1->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame($messageId3, $ackedEnvelope2->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame($messageId4, $ackedEnvelope3->last(TransportMessageIdStamp::class)?->getId());
    }
}
