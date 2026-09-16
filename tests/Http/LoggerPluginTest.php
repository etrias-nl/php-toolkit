<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Http;

use Etrias\PhpToolkit\Http\HttpMessageFormatter;
use Etrias\PhpToolkit\Http\LoggerPlugin;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Http\Client\Exception\HttpException;
use Http\Client\Exception\TransferException;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Http\Promise\RejectedPromise;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class LoggerPluginTest extends TestCase
{
    public function testSuccessfulResponseIsNotLoggedByDefault(): void
    {
        $handler = new TestHandler();
        $plugin = self::createPlugin($handler);
        $response = new Response(200, ['x-request-id' => 'abc'], 'ok');

        self::assertSame($response, $this->handle($plugin, $response));
        self::assertFalse($handler->hasRecords(Level::Info));
        self::assertSame([], $handler->getRecords());
    }

    public function testSuccessfulResponseIsLoggedInDebug(): void
    {
        $handler = new TestHandler();
        $plugin = self::createPlugin($handler, true);
        $response = new Response(200, ['x-request-id' => 'abc'], 'ok');

        self::assertSame($response, $this->handle($plugin, $response));
        self::assertCount(2, $handler->getRecords());
        [$requestRecord, $responseRecord] = $handler->getRecords();
        self::assertSame(Level::Info, $requestRecord->level);
        self::assertSame(
            <<<'TXT'
                POST /foo HTTP/1.1
                Host: example.com

                bar
                TXT,
            $requestRecord->message,
        );
        self::assertSame(Level::Info, $responseRecord->level);
        self::assertSame(
            <<<'TXT'
                HTTP/1.1 200 OK
                x-request-id: abc

                ok
                TXT,
            $responseRecord->message,
        );
        self::assertSame($requestRecord->context['uid'], $responseRecord->context['uid']);
        self::assertSame('abc', $responseRecord->context['external_request_id']);
        self::assertIsInt($responseRecord->context['milliseconds']);
    }

    public function testSuccessfulResponseIsLoggedWithoutBodyInVerboseMode(): void
    {
        $handler = new TestHandler();
        $plugin = self::createPlugin($handler, false, 1);
        $response = new Response(200, ['x-request-id' => 'abc'], 'ok');

        self::assertSame($response, $this->handle($plugin, $response));
        self::assertCount(2, $handler->getRecords());
        [$requestRecord, $responseRecord] = $handler->getRecords();
        self::assertSame(Level::Info, $requestRecord->level);
        self::assertSame('POST /foo HTTP/1.1', $requestRecord->message);
        self::assertSame(Level::Info, $responseRecord->level);
        self::assertSame('HTTP/1.1 200 OK', $responseRecord->message);
        self::assertSame($requestRecord->context['uid'], $responseRecord->context['uid']);
        self::assertSame('abc', $responseRecord->context['external_request_id']);
    }

    public function testSuccessfulResponseIsLoggedWithBodyInVeryVerboseMode(): void
    {
        $handler = new TestHandler();
        $plugin = self::createPlugin($handler, false, 3);
        $response = new Response(200, ['x-request-id' => 'abc'], 'ok');

        self::assertSame($response, $this->handle($plugin, $response));
        self::assertCount(2, $handler->getRecords());
        [$requestRecord, $responseRecord] = $handler->getRecords();
        self::assertSame(
            <<<'TXT'
                POST /foo HTTP/1.1
                Host: example.com

                bar
                TXT,
            $requestRecord->message,
        );
        self::assertSame(
            <<<'TXT'
                HTTP/1.1 200 OK
                x-request-id: abc

                ok
                TXT,
            $responseRecord->message,
        );
    }

    public function testErrorResponseIsLoggedWithRequestByDefault(): void
    {
        $handler = new TestHandler();
        $plugin = self::createPlugin($handler);
        $response = new Response(404, ['x-request-id' => 'abc'], 'not found');

        self::assertSame($response, $this->handle($plugin, $response));
        self::assertCount(1, $handler->getRecords());
        [$record] = $handler->getRecords();
        self::assertSame(Level::Info, $record->level);
        self::assertSame(
            <<<'TXT'
                Request:
                POST /foo HTTP/1.1

                Response:
                HTTP/1.1 404 Not Found
                x-request-id: abc

                not found
                TXT,
            $record->message,
        );
        self::assertSame('abc', $record->context['external_request_id']);
    }

    public function testHttpExceptionIsLoggedByDefault(): void
    {
        $handler = new TestHandler();
        $plugin = self::createPlugin($handler);
        $request = self::createRequest();
        $response = new Response(500, ['x-request-id' => 'abc'], 'oops');
        $exception = new HttpException('Server error', $request, $response);

        try {
            $this->handle($plugin, $exception, $request);
            self::fail('Expected exception');
        } catch (HttpException $e) {
            self::assertSame($exception, $e);
        }

        self::assertCount(1, $handler->getRecords());
        [$record] = $handler->getRecords();
        self::assertSame(Level::Error, $record->level);
        self::assertSame(
            <<<'TXT'
                HTTP Error: Server error

                Request:
                POST /foo HTTP/1.1
                Host: example.com

                bar

                Response:
                HTTP/1.1 500 Internal Server Error
                x-request-id: abc

                oops
                TXT,
            $record->message,
        );
        self::assertSame($exception, $record->context['exception']);
        self::assertSame('abc', $record->context['external_request_id']);
    }

    public function testTransferExceptionIsLoggedByDefault(): void
    {
        $handler = new TestHandler();
        $plugin = self::createPlugin($handler);
        $exception = new TransferException('Timeout');

        try {
            $this->handle($plugin, $exception);
            self::fail('Expected exception');
        } catch (TransferException $e) {
            self::assertSame($exception, $e);
        }

        self::assertCount(1, $handler->getRecords());
        [$record] = $handler->getRecords();
        self::assertSame(Level::Error, $record->level);
        self::assertSame(
            <<<'TXT'
                HTTP Error: Timeout

                Request:
                POST /foo HTTP/1.1
                Host: example.com

                bar
                TXT,
            $record->message,
        );
        self::assertArrayNotHasKey('external_request_id', $record->context);
    }

    private function handle(LoggerPlugin $plugin, ResponseInterface|\Throwable $result, ?RequestInterface $request = null): ResponseInterface
    {
        $request ??= self::createRequest();
        $next = static function (RequestInterface $actualRequest) use ($request, $result): Promise {
            self::assertSame($request, $actualRequest);

            return $result instanceof \Throwable ? new RejectedPromise($result) : new FulfilledPromise($result);
        };

        return $plugin->handleRequest($request, $next, $next)->wait();
    }

    private static function createPlugin(TestHandler $handler, bool $debug = false, ?int $verbosity = null): LoggerPlugin
    {
        return new LoggerPlugin(new Logger('test', [$handler]), new HttpMessageFormatter($debug, $verbosity), $debug, $verbosity);
    }

    private static function createRequest(): RequestInterface
    {
        return new Request('POST', 'https://example.com/foo', [], 'bar');
    }
}
