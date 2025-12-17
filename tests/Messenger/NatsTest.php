<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Basis\Nats\Client;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ConnectionRegistry;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Stamp\DeduplicateStamp;
use Etrias\PhpToolkit\Messenger\Stamp\RejectDelayStamp;
use Etrias\PhpToolkit\Messenger\Stamp\ReplyToStamp;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransport;
use Etrias\PhpToolkit\Messenger\Transport\NatsTransportFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineReceivedStamp;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @internal
 */
final class NatsTest extends TestCase
{
    public function testTransportFactory(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createStub(NormalizerInterface::class), $this->noFallbackTransportFactory());

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
        $factory = new NatsTransportFactory(new MessageMap([]), $logger, new NullLogger(), $normalizer, $this->noFallbackTransportFactory());
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
                [%s] test.INFO: Message "{message}" sent to transport {"payload":"\"O:8:\\\"stdClass\\\":1:{s:5:\\\"test1\\\";b:1;}\"","duplicate":null,"stream":"testTransport%s","message":"stdClass","message_id":"%s"} []
                %A
                TXT,
            implode("\n", array_map(static fn (LogRecord $record): string => trim((string) $record->formatted), $logHandler->getRecords()))
        );
    }

    public function testDeduplication(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createStub(NormalizerInterface::class), $this->noFallbackTransportFactory());
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
        self::assertTrue(\is_string($messageId3) && $messageId3 === Uuid::fromString($messageId3)->toBase58());
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

    #[TestWith([true])]
    #[TestWith([false])]
    public function testRedelivery(bool $enabled): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createStub(NormalizerInterface::class), $this->noFallbackTransportFactory());
        $transport = $factory->createTransport('nats://nats?replicas=1&ack_wait=0.4&stream='.uniqid(__FUNCTION__), ['redeliver' => $enabled], new PhpSerializer());
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

        if (!$enabled) {
            self::assertCount(0, $receivedEnvelopes);

            return;
        }

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

    public function testKeepalive(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createStub(NormalizerInterface::class), $this->noFallbackTransportFactory());
        $transport = $factory->createTransport('nats://nats?replicas=1&ack_wait=0.2&stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        $transport->send(Envelope::wrap((object) ['test1' => true]));

        usleep(50_000);

        $receivedEnvelopes = $transport->get();

        self::assertCount(1, $receivedEnvelopes);

        usleep(125_000);

        $transport->keepalive($receivedEnvelopes[0]);

        usleep(125_000);

        $receivedEnvelopes = $transport->get();

        self::assertCount(0, $receivedEnvelopes);
    }

    public function testFallback(): void
    {
        $connectionRegistry = $this->createMock(ConnectionRegistry::class);
        $connectionRegistry->expects(self::once())->method('getConnection')->willReturn($fallbackConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $fallbackFactory = new DoctrineTransportFactory($connectionRegistry);
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createStub(NormalizerInterface::class), $fallbackFactory);
        $transport = $factory->createTransport('nats://foo?timeout=1&stream='.uniqid(__FUNCTION__), ['fallback_transport' => ['doctrine://foo', [], new PhpSerializer()]], new PhpSerializer());

        $envelope = $transport->send(Envelope::wrap((object) ['test1' => true]));
        $fallbackResult = $fallbackConnection->fetchAllAssociative('select * from messenger_messages');

        self::assertCount(1, $fallbackResult);
        self::assertNull($fallbackResult[0]['delivered_at']);

        $envelope = $envelope->with(new ReplyToStamp('fallback'), new DoctrineReceivedStamp((string) $fallbackResult[0]['id']));

        $transport->reject($envelope);

        self::assertSame($fallbackResult, $fallbackConnection->fetchAllAssociative('select * from messenger_messages'));

        $transport->ack($envelope);

        self::assertSame([], $fallbackConnection->fetchAllAssociative('select * from messenger_messages'));
    }

    public function testReconnect(): void
    {
        $factory = new NatsTransportFactory(new MessageMap([]), new NullLogger(), new NullLogger(), $this->createStub(NormalizerInterface::class), $this->noFallbackTransportFactory());
        $transport = $factory->createTransport('nats://nats?replicas=1&stream='.uniqid(__FUNCTION__), [], new PhpSerializer());
        $transport->setup();

        $transport->send(Envelope::wrap((object) ['test1' => true]));

        $socket = self::getSocket($transport);
        fclose($socket);

        self::assertCount(1, $transport->get());
        self::assertMessageCount(1, $transport);

        $socket = self::getSocket($transport);
        fclose($socket);

        $transport->send(Envelope::wrap((object) ['test2' => true]));

        self::assertCount(1, $transport->get());
        self::assertMessageCount(2, $transport);
    }

    private static function assertMessageCount(int $expectedCount, NatsTransport $transport, bool $wait = true): void
    {
        if ($wait) {
            // wait for updated message count
            usleep(50_000);
        }

        self::assertSame($expectedCount, $transport->getMessageCount());
    }

    /**
     * @return resource
     */
    private static function getSocket(NatsTransport $transport): mixed
    {
        /** @var Client $client */
        $client = (new \ReflectionProperty($transport, 'client'))->getValue($transport);

        self::assertNotNull($client->connection);

        $socket = (new \ReflectionProperty($client->connection, 'socket'))->getValue($client->connection);

        self::assertIsResource($socket);

        return $socket;
    }

    private function noFallbackTransportFactory(): MockObject&TransportFactoryInterface
    {
        $transportFactory = $this->createMock(TransportFactoryInterface::class);
        $transportFactory->expects(self::never())->method('createTransport');

        return $transportFactory;
    }
}
