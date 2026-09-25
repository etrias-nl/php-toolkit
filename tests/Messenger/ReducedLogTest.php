<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\MessageMap;
use Etrias\PhpToolkit\Messenger\Middleware\LogMiddleware;
use Etrias\PhpToolkit\Messenger\Monolog\ReducedLogHandler;
use Etrias\PhpToolkit\Messenger\Stamp\ReducedLogStamp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * @internal
 */
final class ReducedLogTest extends TestCase
{
    public function testHandler(): void
    {
        $logMiddleware = new LogMiddleware();
        $logHandler = new TestHandler();
        $logger = new Logger('test', [
            new ReducedLogHandler(new MessageMap([], [\stdClass::class => [ReducedLogStamp::class => [new ReducedLogStamp()]]])),
            $logHandler,
        ], [$logMiddleware]);

        $logger->info('context class', ['class' => \stdClass::class]);
        $logger->info('context message', ['message' => \stdClass::class]);
        $logger->warning('warning', ['class' => \stdClass::class]);
        $logger->info('other class', ['class' => \ArrayObject::class]);
        $logger->info('no class');

        $bus = new MessageBus([$logMiddleware, new class($logger) implements MiddlewareInterface {
            public function __construct(
                private readonly Logger $logger,
            ) {}

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                $this->logger->info('handling '.$envelope->getMessage()::class);

                return $stack->next()->handle($envelope, $stack);
            }
        }]);
        $bus->dispatch(new \stdClass());
        $bus->dispatch(new \ArrayObject());

        self::assertSame(
            ['warning', 'other class', 'no class', 'handling ArrayObject'],
            array_map(static fn (LogRecord $record): string => $record->message, $logHandler->getRecords())
        );
    }
}
