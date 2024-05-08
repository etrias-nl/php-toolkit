<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\DeduplicateStamp;
use Etrias\PhpToolkit\Messenger\Stamp\RejectDelayStamp;
use Etrias\PhpToolkit\Messenger\Stamp\ReplyToStamp;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransportFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
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
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createMock(NormalizerInterface::class));

        self::assertTrue($factory->supports('nats://foo', []));
        self::assertFalse($factory->supports('natss://foo', []));

        $transport = $factory->createTransport('nats://foo?stream='.uniqid(__FUNCTION__), [], new PhpSerializer());

        self::assertInstanceOf(NatsTransport::class, $transport);

        self::expectException(\RuntimeException::class);

        $factory->createTransport('nats://foo?streamm=bar', [], new PhpSerializer());
    }

    public function testTransport(): void
    {
        $logger = new Logger('test', [$logHandler = new TestHandler()]);
        $normalizer = $this->createMock(NormalizerInterface::class);
        $normalizer->expects(self::once())->method('normalize')->willReturnCallback(static fn (mixed $data): string => serialize($data));
        $factory = new NatsTransportFactory(new MessageMap([]), $logger, new NullLogger(), $normalizer);
        $transport = $factory->createTransport('nats://nats?replicas=1&stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        // initially empty
        self::assertSame(0, $transport->getMessageCount());

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

        $ackWaitTimeoutMin = new \DateTime($ackWaitTimeout = '+5 minutes');
        $receivedEnvelopes = $transport->get();
        $ackWaitTimeoutMax = new \DateTime($ackWaitTimeout);

        // message fetched
        self::assertCount(1, $receivedEnvelopes);
        self::assertMessageCount(1, $transport);
        self::assertSame((array) $message, (array) $receivedEnvelopes[0]->getMessage());
        self::assertSame($messageId, $receivedEnvelopes[0]->last(TransportMessageIdStamp::class)?->getId());

        $replyTo = $receivedEnvelopes[0]->last(ReplyToStamp::class);

        self::assertNotNull($replyTo);
        self::assertStringStartsWith('$JS.ACK.', $replyTo->id);
        self::assertGreaterThanOrEqual($ackWaitTimeoutMin, $replyTo->expiresAt);
        self::assertLessThanOrEqual($ackWaitTimeoutMax, $replyTo->expiresAt);

        $transport->ack($receivedEnvelopes[0]);

        // message acked
        self::assertMessageCount(0, $transport);
        self::assertSame([], $transport->get());

        // logs
        self::assertStringMatchesFormat(
            <<<'TXT'
                %A
                [%s] test.INFO: Message "{message}" sent to transport {"payload":"O:8:\"stdClass\":1:{s:5:\"test1\";b:1;}","duplicate":null,"stream":"testTransport%s","message":"stdClass","message_id":"%s"} []
                %A
                TXT,
            implode("\n", array_map(static fn (LogRecord $record): string => trim((string) $record->formatted), $logHandler->getRecords()))
        );
    }

    public function testDeduplication(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createMock(NormalizerInterface::class));
        $transport = $factory->createTransport('nats://nats?replicas=1&stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        $envelope1 = $transport->send(Envelope::wrap((object) ['test_1' => true]));
        $messageId1 = $envelope1->last(TransportMessageIdStamp::class)?->getId();
        $messageId2 = $transport->send(Envelope::wrap((object) ['test_1' => true], [new SentStamp(self::class, 'sender')]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId3 = $transport->send(Envelope::wrap((object) ['test_1' => true], [new DeduplicateStamp(false)]))->last(TransportMessageIdStamp::class)?->getId();
        $messageId4 = $transport->send(Envelope::wrap((object) ['test_2' => true]))->last(TransportMessageIdStamp::class)?->getId();

        self::assertMessageCount(3, $transport);
        self::assertTrue(\is_string($messageId1) && 32 === \strlen($messageId1));
        self::assertSame($messageId1, $messageId2);
        self::assertTrue(\is_string($messageId3) && Uuid::isValid($messageId3));
        self::assertNotSame($messageId3, $messageId1);
        self::assertTrue(\is_string($messageId4) && 32 === \strlen($messageId4));
        self::assertNotSame($messageId4, $messageId1);
        self::assertNotSame($messageId4, $messageId3);

        $receivedEnvelopes1 = $transport->get();

        self::assertCount(1, $receivedEnvelopes1);
        self::assertSame($messageId1, $receivedEnvelopes1[0]->last(TransportMessageIdStamp::class)?->getId());

        $transport->ack($receivedEnvelopes1[0]);

        self::assertMessageCount(2, $transport);

        // deduplicated after ack by NATS due hard window limit
        $transport->send($envelope1);

        self::assertMessageCount(2, $transport);

        $receivedEnvelopes2 = $transport->get();

        self::assertCount(1, $receivedEnvelopes2);
        self::assertSame($messageId3, $receivedEnvelopes2[0]->last(TransportMessageIdStamp::class)?->getId());

        $transport->ack($receivedEnvelopes2[0]);

        self::assertMessageCount(1, $transport);

        $receivedEnvelopes3 = $transport->get();

        self::assertCount(1, $receivedEnvelopes3);
        self::assertSame($messageId4, $receivedEnvelopes3[0]->last(TransportMessageIdStamp::class)?->getId());

        $transport->ack($receivedEnvelopes3[0]);

        self::assertMessageCount(0, $transport);
        self::assertSame([], $transport->get());
    }

    public function testRedelivery(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createMock(NormalizerInterface::class));
        $transport = $factory->createTransport('nats://nats?replicas=1&ack_wait=0.4&stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        $messageId = $transport->send(Envelope::wrap((object) ['test1' => true]))->last(TransportMessageIdStamp::class)?->getId();

        usleep(50_000);

        $receivedEnvelopes = $transport->get();

        self::assertCount(1, $receivedEnvelopes);
        self::assertSame($messageId, $receivedEnvelopes[0]->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame([], $transport->get());

        usleep(250_000);

        self::assertSame([], $transport->get());

        usleep(250_000);

        $receivedEnvelopes = $transport->get();

        self::assertCount(1, $receivedEnvelopes);
        self::assertSame($messageId, $receivedEnvelopes[0]->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame([], $transport->get());

        $transport->reject($receivedEnvelopes[0]);

        usleep(50_000);

        $receivedEnvelopes = $transport->get();

        self::assertCount(1, $receivedEnvelopes);
        self::assertSame($messageId, $receivedEnvelopes[0]->last(TransportMessageIdStamp::class)?->getId());

        $transport->reject($receivedEnvelopes[0]->with(new RejectDelayStamp(90)));

        usleep(50_000);

        self::assertSame([], $transport->get());

        usleep(50_000);

        $receivedEnvelopes = $transport->get();

        self::assertCount(1, $receivedEnvelopes);
        self::assertSame($messageId, $receivedEnvelopes[0]->last(TransportMessageIdStamp::class)?->getId());
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
