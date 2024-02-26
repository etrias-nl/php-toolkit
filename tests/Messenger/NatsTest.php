<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Counter\InMemoryCounter;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\DeduplicateStamp;
use Etrias\PhpToolkit\Messenger\Stamp\ReplyToStamp;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
final class NatsTest extends TestCase
{
    public function testTransportFactory(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new InMemoryCounter(), new NullLogger(), $this->createMock(NormalizerInterface::class));

        self::assertTrue($factory->supports('nats://foo', []));
        self::assertFalse($factory->supports('natss://foo', []));

        $transport = $factory->createTransport('nats://foo?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());

        self::assertInstanceOf(NatsTransport::class, $transport);

        self::expectException(\RuntimeException::class);

        $factory->createTransport('nats://foo?streamm=bar', [], new PhpSerializer());
    }

    public function testTransport(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new InMemoryCounter(), new NullLogger(), $this->createMock(NormalizerInterface::class));
        $transport = $factory->createTransport('nats://nats?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        // initially empty
        self::assertSame(0, $transport->getMessageCount());
        self::assertSame([], $transport->getMessageCounts());

        $message = (object) ['test1' => true];
        $prevMessageId = Uuid::v4()->toRfc4122();
        $envelope = $transport->send(Envelope::wrap($message, [new TransportMessageIdStamp($prevMessageId)]));
        $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();

        // new message ID
        self::assertSame($message, $envelope->getMessage());
        self::assertTrue(\is_string($messageId) && 32 === \strlen($messageId));
        self::assertNotSame($messageId, $prevMessageId);

        $transport->setup();

        // counts unaffected by setup
        self::assertMessageCount(1, $transport);
        self::assertSame([\stdClass::class => 1], $transport->getMessageCounts());

        $ackWaitTimeoutMin = new \DateTime($ackWaitTimeout = '+5 minutes');
        $sentEnvelopes = $transport->get();
        $ackWaitTimeoutMax = new \DateTime($ackWaitTimeout);

        // message fetched
        self::assertCount(1, $sentEnvelopes);
        self::assertMessageCount(1, $transport);
        self::assertSame([\stdClass::class => 1], $transport->getMessageCounts());
        self::assertSame((array) $message, (array) $sentEnvelopes[0]->getMessage());
        self::assertSame($messageId, $sentEnvelopes[0]->last(TransportMessageIdStamp::class)?->getId());

        $replyTo = $sentEnvelopes[0]->last(ReplyToStamp::class);

        self::assertNotNull($replyTo);
        self::assertStringStartsWith('$JS.ACK.', $replyTo->id);
        self::assertGreaterThanOrEqual($ackWaitTimeoutMin, $replyTo->expiresAt);
        self::assertLessThanOrEqual($ackWaitTimeoutMax, $replyTo->expiresAt);

        $transport->ack($sentEnvelopes[0]);

        // message acked
        self::assertMessageCount(0, $transport);
        self::assertSame([], $transport->get());
        self::assertSame([], $transport->getMessageCounts());
    }

    public function testDeduplication(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new InMemoryCounter(), new NullLogger(), $this->createMock(NormalizerInterface::class));
        $transport = $factory->createTransport('nats://nats?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        $messageId1 = $transport->send(Envelope::wrap((object) ['test_1' => true]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId2 = $transport->send(Envelope::wrap((object) ['test_1' => true], [new SentStamp(self::class, 'sender')]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId3 = $transport->send(Envelope::wrap((object) ['test_1' => true], [new DeduplicateStamp(false)]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId4 = $transport->send(Envelope::wrap((object) ['test_2' => true]))->last(TransportMessageIdStamp::class)?->getId();

        self::assertMessageCount(3, $transport);
        self::assertSame([\stdClass::class => 3], $transport->getMessageCounts());
        self::assertTrue(\is_string($messageId1) && 32 === \strlen($messageId1));
        self::assertSame($messageId1, $messageId2);
        self::assertTrue(\is_string($messageId3) && Uuid::isValid($messageId3));
        self::assertNotSame($messageId3, $messageId1);
        self::assertTrue(\is_string($messageId4) && 32 === \strlen($messageId4));
        self::assertNotSame($messageId4, $messageId1);
        self::assertNotSame($messageId4, $messageId3);

        $sentEnvelopes1 = $transport->get();

        self::assertCount(1, $sentEnvelopes1);
        self::assertSame($messageId1, $sentEnvelopes1[0]->last(TransportMessageIdStamp::class)?->getId());

        $transport->ack($sentEnvelopes1[0]);

        self::assertMessageCount(2, $transport);
        self::assertSame([\stdClass::class => 2], $transport->getMessageCounts());

        $sentEnvelopes2 = $transport->get();

        self::assertCount(1, $sentEnvelopes2);
        self::assertSame($messageId3, $sentEnvelopes2[0]->last(TransportMessageIdStamp::class)?->getId());

        $transport->ack($sentEnvelopes2[0]);

        self::assertMessageCount(1, $transport);
        self::assertSame([\stdClass::class => 1], $transport->getMessageCounts());

        $sentEnvelopes3 = $transport->get();

        self::assertCount(1, $sentEnvelopes3);
        self::assertSame($messageId4, $sentEnvelopes3[0]->last(TransportMessageIdStamp::class)?->getId());

        $transport->ack($sentEnvelopes3[0]);

        self::assertMessageCount(0, $transport);
        self::assertSame([], $transport->get());
        self::assertSame([], $transport->getMessageCounts());
    }

    public function testRedelivery(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new InMemoryCounter(), new NullLogger(), $this->createMock(NormalizerInterface::class));
        $transport = $factory->createTransport('nats://nats?ack_wait=0.4&stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        $messageId = $transport->send(Envelope::wrap((object) ['test1' => true]))->last(TransportMessageIdStamp::class)?->getId();

        self::assertSame($messageId, $transport->get()[0]?->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame([], $transport->get());

        usleep(70_000);

        self::assertSame($messageId, $transport->get()[0]?->last(TransportMessageIdStamp::class)?->getId());
    }

    private static function assertMessageCount(int $expectedCount, NatsTransport $transport, bool $wait = true): void
    {
        if ($wait) {
            // wait for updated message count
            usleep(50_000);
        }

        self::assertSame($expectedCount, $transport->getMessageCount());
    }
}
