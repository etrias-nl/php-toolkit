<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Serializer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class DeflateSerializer implements SerializerInterface
{
    public function __construct(
        private readonly SerializerInterface $serializer = new PhpSerializer(),
    ) {}

    public function decode(array $encodedEnvelope): Envelope
    {
        return $this->serializer->decode(array_map(
            static fn (string $value): string => (false === $inflated = @gzinflate($value)) ? $value : $inflated,
            $encodedEnvelope
        ));
    }

    public function encode(Envelope $envelope): array
    {
        return array_map(
            static fn (string $value): string => (false === $deflated = gzdeflate($value)) ? $value : $deflated,
            $this->serializer->encode($envelope)
        );
    }
}
