<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Transport;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Etrias\PhpToolkit\Counter\Counter;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class NatsTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly MessageMap $messageMap,
        #[Autowire(service: '.messenger.counter')]
        private readonly Counter $counter,
        #[Target(name: 'messenger.logger')]
        private readonly LoggerInterface $logger,
        private readonly NormalizerInterface $normalizer,
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
            $this->logger,
            $this->normalizer,
            $queryParts['stream'],
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'nats://') || str_starts_with($dsn, 'natsstreaming://');
    }
}
