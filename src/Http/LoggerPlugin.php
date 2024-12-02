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
use Symfony\Component\Uid\Uuid;

final class LoggerPlugin implements Plugin
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Formatter $formatter,
    ) {}

    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $start = hrtime(true) / 1E6;
        $uid = Uuid::v7()->toBase58();
        $this->logger->info($this->formatter->formatRequest($request), ['uid' => $uid]);

        return $next($request)->then(function (ResponseInterface $response) use ($start, $uid, $request): ResponseInterface {
            $milliseconds = (int) round(hrtime(true) / 1E6 - $start);
            $formattedResponse = method_exists($this->formatter, 'formatResponseForRequest') ? $this->formatter->formatResponseForRequest($response, $request) : $this->formatter->formatResponse($response);
            $this->logger->info($formattedResponse, [
                'milliseconds' => $milliseconds,
                'uid' => $uid,
                'client_request_id' => $response->getHeader('x-request-id')[0] ?? null,
            ]);

            return $response;
        }, function (Exception $e) use ($request, $start, $uid): void {
            $milliseconds = (int) round(hrtime(true) / 1E6 - $start);
            $error = 'HTTP Error: '.$e->getMessage()."\n\nRequest:\n".$this->formatter->formatRequest($request, $e);
            if ($e instanceof HttpException) {
                $response = $e->getResponse();
                $formattedResponse = method_exists($this->formatter, 'formatResponseForRequest') ? $this->formatter->formatResponseForRequest($response, $e->getRequest()) : $this->formatter->formatResponse($response);
                $this->logger->error($error."\n\nResponse:\n".$formattedResponse, [
                    'exception' => $e,
                    'milliseconds' => $milliseconds,
                    'uid' => $uid,
                    'external_request_id' => $response->getHeader('x-request-id')[0] ?? null,
                ]);
            } else {
                $this->logger->error($error, [
                    'exception' => $e,
                    'milliseconds' => $milliseconds,
                    'uid' => $uid,
                ]);
            }

            throw $e;
        });
    }
}
