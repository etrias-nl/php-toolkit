<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestInterface;

final class GuzzleClientFactory
{
    public static function create(PsrClientInterface $client): Client
    {
        return new Client([
            'handler' => new HandlerStack(static fn (RequestInterface $request): PromiseInterface => new FulfilledPromise($client->sendRequest($request))),
        ]);
    }
}
