<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Serializer;

use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\IdentityMarshaller;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class MarshallingSerializer implements SerializerInterface
{
    public function __construct(
        private readonly MarshallerInterface $marshaller = new DeflateMarshaller(new IdentityMarshaller()),
        private readonly SerializerInterface $serializer = new PhpSerializer(),
    ) {}

    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            $unmarshalled = array_map(fn (string $value): mixed => $this->marshaller->unmarshall($value), $encodedEnvelope);
        } catch (\Throwable $e) {
            throw new MessageDecodingFailedException($e->getMessage(), 0, $e);
        }

        return $this->serializer->decode($unmarshalled);
    }

    public function encode(Envelope $envelope): array
    {
        $encoded = $this->marshaller->marshall($this->serializer->encode($envelope), $failed);

        if ($failed) {
            throw new \RuntimeException('Failed to marshall envelope keys: '.implode(', ', $failed));
        }

        return $encoded;
    }
}
