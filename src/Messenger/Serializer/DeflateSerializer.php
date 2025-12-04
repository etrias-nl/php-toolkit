<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Serializer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class DeflateSerializer implements SerializerInterface
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private readonly SerializerInterface $serializer,
    ) {}

    public function decode(array $encodedEnvelope): Envelope
    {
        $encodedEnvelope['body'] = (false === $inflated = @gzinflate($encodedEnvelope['body'])) ? $encodedEnvelope['body'] : $inflated;

        return $this->serializer->decode($encodedEnvelope);
    }

    public function encode(Envelope $envelope): array
    {
        $encoded = $this->serializer->encode($envelope);
        $encoded['body'] = (false === $deflated = gzdeflate($encoded['body'])) ? $encoded['body'] : $deflated;

        return $encoded;
    }
}
