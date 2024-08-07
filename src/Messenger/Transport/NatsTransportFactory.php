<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Transport;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
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
        #[Target(name: 'messenger.logger')]
        private readonly LoggerInterface $logger,
        #[Target(name: 'nats.logger')]
        private readonly LoggerInterface $clientLogger,
        private readonly NormalizerInterface $normalizer,
        #[Autowire(service: 'messenger.transport_factory')]
        private readonly TransportFactoryInterface $fallbackFactory,
    ) {}

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): NatsTransport
    {
        if (!\is_array($urlParts = parse_url($dsn))) {
            throw new \RuntimeException('Invalid DSN.');
        }

        $config = array_intersect_key($urlParts, array_flip(['host', 'port', 'user', 'pass']));
        $queryParts = [];
        parse_str($urlParts['query'] ?? '', $queryParts);
        $name = $options['transport_name'] ?? null;
        $fallbackTransport = $options['fallback_transport'] ?? null;
        unset($options['transport_name'], $options['fallback_transport']);
        $options = $queryParts + $options + $defaults = [
            'stream' => $name,
            'replicas' => 3,
            'timeout' => 3.0,
            'redeliver' => true,
            'ack_wait' => 300,
            'deduplicate_window' => 10,
        ];

        if ($diff = array_diff_key($options, $defaults)) {
            throw new \RuntimeException(\sprintf('Unsupported transport options: %s', implode(', ', array_keys($diff))));
        }
        if (!\is_string($stream = $options['stream']) || '' === trim($stream)) {
            throw new \RuntimeException('Missing stream name.');
        }

        $config['timeout'] = is_numeric($options['timeout']) ? (float) $options['timeout'] : throw new \RuntimeException('Invalid option "timeout" for stream "'.$stream.'".');

        if (null !== $fallbackTransport) {
            $fallbackTransport = $this->fallbackFactory->createTransport(...$fallbackTransport);
        }

        return new NatsTransport(
            new Client(new Configuration($config), $this->clientLogger),
            $serializer,
            $this->messageMap,
            $this->logger,
            $this->normalizer,
            $stream,
            filter_var($options['replicas'], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? throw new \RuntimeException('Invalid option "replicas" for stream "'.$stream.'"'),
            filter_var($options['redeliver'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new \RuntimeException('Invalid option "redeliver" for stream "'.$stream.'"'),
            filter_var($options['ack_wait'], FILTER_VALIDATE_INT | FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? throw new \RuntimeException('Invalid option "ack_wait" for stream "'.$stream.'"'),
            filter_var($options['deduplicate_window'], FILTER_VALIDATE_INT | FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? throw new \RuntimeException('Invalid option "deduplicate_window" for stream "'.$stream.'"'),
            $fallbackTransport,
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'nats://') || str_starts_with($dsn, 'natsstreaming://');
    }
}
