<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Transport;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Etrias\PhpToolkit\Messenger\MessageMap;
use Psr\Log\LoggerInterface;
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
        unset($options['transport_name']);
        $options = $queryParts + $options + $defaults = [
            'stream' => $name,
            'replicas' => 3,
            'timeout' => 3.0,
            'ack_wait' => 300,
            'deduplicate_window' => 10,
        ];

        if ($diff = array_diff_key($options, $defaults)) {
            throw new \RuntimeException(sprintf('Unsupported transport options: %s', implode(', ', array_keys($diff))));
        }
        if (!\is_string($stream = $options['stream']) || '' === trim($stream)) {
            throw new \RuntimeException('Missing stream name.');
        }

        $config['timeout'] = is_numeric($options['timeout']) ? (float) $options['timeout'] : throw new \RuntimeException('Invalid option "timeout" for stream "'.$stream.'".');

        return new NatsTransport(
            new Client(new Configuration($config), $this->clientLogger),
            $serializer,
            $this->messageMap,
            $this->logger,
            $this->normalizer,
            $stream,
            \is_int($options['replicas']) || (\is_string($options['replicas']) && ctype_digit($options['replicas'])) ? (int) $options['replicas'] : throw new \RuntimeException('Invalid option "replicas" for stream "'.$stream.'"'),
            is_numeric($options['ack_wait']) ? (float) $options['ack_wait'] : throw new \RuntimeException('Invalid option "ack_wait" for stream "'.$stream.'"'),
            is_numeric($options['deduplicate_window']) ? (float) $options['deduplicate_window'] : throw new \RuntimeException('Invalid option "deduplicate_window" for stream "'.$stream.'"'),
        );
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'nats://') || str_starts_with($dsn, 'natsstreaming://');
    }
}
