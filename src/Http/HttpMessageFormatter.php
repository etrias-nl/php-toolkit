<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Http;

use Http\Message\Formatter;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class HttpMessageFormatter implements Formatter
{
    private const SENSITIVE_REQUEST_HEADERS = [
        'authorization',
        'proxy-authorization',
        'www-authenticate',
        'proxy-authenticate',
        'x-amz-access-token',
        'x-amz-security-token',
        'x-auth-apikey',
        'apikey',
        'api-key',
        'api_key',
    ];
    private const NON_SENSITIVE_RESPONSE_HEADERS = [
        'content-type',
        'retry-after',
        'x-request-id',
        'x-amzn-requestid',
    ];

    public function __construct(
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
        #[Autowire(env: 'int:SHELL_VERBOSITY')]
        private readonly int $verbosity,
    ) {}

    public function formatRequest(RequestInterface $request, ?\Throwable $error = null): string
    {
        $message = \sprintf('%s %s HTTP/%s', $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());
        $full = $this->debug || null !== $error || 3 === $this->verbosity;

        if (!$full) {
            return $message;
        }

        foreach (array_diff_key($request->getHeaders(), array_flip(self::SENSITIVE_REQUEST_HEADERS)) as $name => $values) {
            $message .= "\n".$name.': '.implode(', ', $values);
        }

        if ('' !== $body = self::getMessageBody($request)) {
            $message .= "\n\n".$body;
        }

        return $message;
    }

    public function formatResponse(ResponseInterface $response): string
    {
        $message = \sprintf('HTTP/%s %s %s', $response->getProtocolVersion(), $response->getStatusCode(), $response->getReasonPhrase());
        $full = $this->debug || ($response->getStatusCode() >= 400) || 3 === $this->verbosity;

        if (!$full) {
            return $message;
        }

        foreach (array_intersect_key($response->getHeaders(), array_flip(self::NON_SENSITIVE_RESPONSE_HEADERS)) as $name => $values) {
            $message .= "\n".$name.': '.implode(', ', $values);
        }

        if ('' !== $body = self::getMessageBody($response)) {
            $message .= "\n\n".$body;
        }

        return $message;
    }

    public function formatResponseForRequest(ResponseInterface $response, RequestInterface $request): string
    {
        return $this->formatResponse($response);
    }

    private static function getMessageBody(MessageInterface $message): string
    {
        $contentType = $message->getHeader('content-type')[0] ?? null;

        if (\in_array($contentType, ['application/octet-stream', 'application/pdf'], true)) {
            return '***BINARY***';
        }

        $body = (string) $message->getBody();

        if (str_starts_with($body, '%PDF-') && str_ends_with($body, '%%EOF')) {
            return '***BINARY***';
        }

        return $body;
    }
}
