<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Middleware\LogMiddleware;
use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * @internal
 */
final class LogTest extends TestCase
{
    public function testMiddleware(): void
    {
        $logMiddleware = new LogMiddleware([
            static function (LogRecord $record, Envelope $envelope): LogRecord {
                $record->extra['extra_processor'] = $envelope->getMessage()::class;

                return $record;
            },
        ]);
        $logHandler = new TestHandler();
        $envelopeMiddleware = new class(new Logger('test', [$logHandler], [$logMiddleware])) implements MiddlewareInterface {
            /** @psalm-suppress PropertyNotSetInConstructor */
            public MessageBus $bus;

            public function __construct(
                private readonly Logger $logger,
            ) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $message = $envelope->getMessage();

                $this->logger->info(json_encode($message));

                if ($message->nest) {
                    $this->bus->dispatch((object) ['nest' => false], [new TransportMessageIdStamp('NestedID')]);
                }

                return $stack->next()->handle($envelope, $stack);
            }
        };
        $bus = $envelopeMiddleware->bus = new MessageBus([$logMiddleware, $envelopeMiddleware]);

        $bus->dispatch((object) ['test1' => true, 'nest' => true]);
        $bus->dispatch((object) ['test2' => true, 'nest' => false]);
        $bus->dispatch((object) ['test3' => true, 'nest' => true], [new TransportMessageIdStamp('ID'), new OriginTransportMessageIdStamp('OriginID')]);

        self::assertStringMatchesFormat(
            <<<'TXT'
                [%s] test.INFO: {"test1":true,"nest":true} [] {"messenger":{"id":"%s","origin":null,"message":"stdClass"},"extra_processor":"stdClass"}
                [%s] test.INFO: {"nest":false} [] {"messenger":{"id":"NestedID","origin":null,"message":"stdClass"},"extra_processor":"stdClass"}
                [%s] test.INFO: {"test2":true,"nest":false} [] {"messenger":{"id":"%s","origin":null,"message":"stdClass"},"extra_processor":"stdClass"}
                [%s] test.INFO: {"test3":true,"nest":true} [] {"messenger":{"id":"ID","origin":"OriginID","message":"stdClass"},"extra_processor":"stdClass"}
                [%s] test.INFO: {"nest":false} [] {"messenger":{"id":"NestedID","origin":"ID","message":"stdClass"},"extra_processor":"stdClass"}
                TXT,
            implode("\n", array_map(static fn (LogRecord $record): string => trim((string) $record->formatted), $logHandler->getRecords()))
        );
    }
}
