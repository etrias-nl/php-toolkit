<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Http;

use Http\Client\Common\Plugin;
use Http\Client\Exception;
use Http\Client\Exception\HttpException;
use Http\Message\Formatter;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class LoggerPlugin implements Plugin
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Formatter $formatter = new HttpMessageFormatter(false),
    ) {}

    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $start = hrtime(true) / 1E6;
        $uid = uniqid('', true);
        $this->logger->info(\sprintf("Sending request:\n%s", $this->formatter->formatRequest($request)), ['uid' => $uid]);

        return $next($request)->then(function (ResponseInterface $response) use ($start, $uid, $request): ResponseInterface {
            $milliseconds = (int) round(hrtime(true) / 1E6 - $start);
            $formattedResponse = method_exists($this->formatter, 'formatResponseForRequest')
                ? $this->formatter->formatResponseForRequest($response, $request)
                : $this->formatter->formatResponse($response);
            $this->logger->info(
                \sprintf("Received response:\n%s", $formattedResponse),
                [
                    'milliseconds' => $milliseconds,
                    'uid' => $uid,
                ]
            );

            return $response;
        }, function (Exception $exception) use ($request, $start, $uid): void {
            $milliseconds = (int) round(hrtime(true) / 1E6 - $start);
            if ($exception instanceof HttpException) {
                $formattedResponse = method_exists($this->formatter, 'formatResponseForRequest')
                    ? $this->formatter->formatResponseForRequest($exception->getResponse(), $exception->getRequest())
                    : $this->formatter->formatResponse($exception->getResponse());
                $this->logger->error(
                    \sprintf("Error:\n%s\nwith response:\n%s", $exception->getMessage(), $formattedResponse),
                    [
                        'exception' => $exception,
                        'milliseconds' => $milliseconds,
                        'uid' => $uid,
                    ]
                );
            } else {
                $this->logger->error(
                    \sprintf("Error:\n%s\nwhen sending request:\n%s", $exception->getMessage(), $this->formatter->formatRequest($request)),
                    [
                        'exception' => $exception,
                        'milliseconds' => $milliseconds,
                        'uid' => $uid,
                    ]
                );
            }

            throw $exception;
        });
    }
}
