<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Middleware\LogMiddleware;
use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Etrias\PhpToolkit\Messenger\Stamp\SecurityStamp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * @internal
 */
final class LogTest extends TestCase
{
    public function testMiddleware(): void
    {
        $logMiddleware = new LogMiddleware();
        $logHandler = new TestHandler();
        $logger = new Logger('test', [$logHandler], [$logMiddleware]);
        $envelopeMiddleware = new class($logger) implements MiddlewareInterface {
            public function __construct(private readonly Logger $logger) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $message = $envelope->getMessage();
                $bus = $message->bus ?? null;
                $message->bus = null;

                $this->logger->info('handling1', ['foo' => 'handling']);
                $this->logger->info('handling2');

                if ($bus) {
                    $bus->dispatch((object) ['nested' => true], [new TransportMessageIdStamp('NestedID')]);
                }

                return $stack->next()->handle($envelope, $stack);
            }
        };
        $bus = new MessageBus([$logMiddleware, $envelopeMiddleware]);

        $logger->info('before');
        $bus->dispatch((object) ['test1' => true], [new SecurityStamp(new PreAuthenticatedToken(new InMemoryUser('TestUser', null), 'main'))]);
        $bus->dispatch((object) ['test2' => true, 'bus' => $bus]);
        $bus->dispatch((object) ['test3' => true, 'bus' => $bus], [new TransportMessageIdStamp('ID'), new OriginTransportMessageIdStamp('OriginID')]);
        $logger->info('after', ['foo' => 'after']);

        self::assertStringMatchesFormat(
            <<<'TXT'
                [%a] test.INFO: before [] []
                [%a] test.INFO: handling1 {"messenger":{"id":null,"origin":null,"user":"TestUser","payload":{"stdClass":{"test1":true,"bus":null}}},"foo":"handling"} []
                [%a] test.INFO: handling2 {"messenger":{"id":null,"origin":null,"user":"TestUser"}} []
                [%a] test.INFO: handling1 {"messenger":{"id":null,"origin":null,"user":null,"payload":{"stdClass":{"test2":true,"bus":null}}},"foo":"handling"} []
                [%a] test.INFO: handling2 {"messenger":{"id":null,"origin":null,"user":null}} []
                [%a] test.INFO: handling1 {"messenger":{"id":"NestedID","origin":null,"user":null,"payload":{"stdClass":{"nested":true,"bus":null}}},"foo":"handling"} []
                [%a] test.INFO: handling2 {"messenger":{"id":"NestedID","origin":null,"user":null}} []
                [%a] test.INFO: handling1 {"messenger":{"id":"ID","origin":"OriginID","user":null,"payload":{"stdClass":{"test3":true,"bus":null}}},"foo":"handling"} []
                [%a] test.INFO: handling2 {"messenger":{"id":"ID","origin":"OriginID","user":null}} []
                [%a] test.INFO: handling1 {"messenger":{"id":"NestedID","origin":"ID","user":null,"payload":{"stdClass":{"nested":true,"bus":null}}},"foo":"handling"} []
                [%a] test.INFO: handling2 {"messenger":{"id":"NestedID","origin":"ID","user":null}} []
                [%a] test.INFO: after {"foo":"after"} []
                TXT,
            implode("\n", array_map(static fn (LogRecord $record): string => trim((string) $record->formatted), $logHandler->getRecords()))
        );
    }
}
