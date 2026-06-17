<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Http\Client\HttpAsyncClient;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestInterface;

final class GuzzleClientFactory
{
    public static function create(PsrClientInterface $client, bool $async = false): Client
    {
        if ($async) {
            if (!$client instanceof HttpAsyncClient) {
                throw new \LogicException('No async client provided');
            }

            $handler = new HandlerStack(static function (RequestInterface $request) use ($client): PromiseInterface {
                $promise = $client->sendAsyncRequest($request);

                return (new \ReflectionObject($promise))->getProperty('promise')->getValue($promise);
            });
        } else {
            $handler = new HandlerStack(static fn (RequestInterface $request): PromiseInterface => new FulfilledPromise($client->sendRequest($request)));
        }

        return new Client([
            'handler' => $handler,
        ]);
    }
}
