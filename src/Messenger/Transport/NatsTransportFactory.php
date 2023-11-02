<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Transport;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Etrias\PhpToolkit\Counter\Counter;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;

final class NatsTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly MessageMap $messageMap,
        private readonly Counter $counter,
    ) {}

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): NatsTransport
    {
        $urlParts = parse_url($dsn);
        $queryParts = [];

        parse_str($urlParts['query'] ?? '', $queryParts);

        if (!isset($queryParts['stream'])) {
            throw new \RuntimeException('Missing "stream" parameter in connection string.');
        }

        $config = array_intersect_key($urlParts, array_flip(['host', 'port', 'user', 'pass']));

        return new NatsTransport(
            new Client(new Configuration($config)),
            $serializer,
            $this->messageMap,
            $this->counter,
            $queryParts['stream'],
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'nats://') || str_starts_with($dsn, 'natsstreaming://');
    }
}
