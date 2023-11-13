<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Service\Attribute\Required;

final class LogMiddleware implements MiddlewareInterface, ProcessorInterface
{
    private ?Envelope $currentEnvelope = null;
    private bool $loggedPayload = false;
    private ?NormalizerInterface $normalizer = null;

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null === $this->currentEnvelope) {
            return $record;
        }

        $record->extra['messenger']['id'] = $this->currentEnvelope->last(TransportMessageIdStamp::class)?->getId();
        $record->extra['messenger']['origin'] = $this->currentEnvelope->last(OriginTransportMessageIdStamp::class)?->id;

        if (!$this->loggedPayload && $record->level->isHigherThan(Level::Debug)) {
            if (null === $this->normalizer) {
                throw new \LogicException('Normalizer is not set');
            }

            // https://github.com/symfony/symfony/issues/52564
            $this->normalizer->normalize(new \stdClass());

            $message = $this->currentEnvelope->getMessage();
            $record->extra['messenger']['message'] = $message::class;
            $record->extra['messenger']['payload'] = $this->normalizer->normalize($message, null, [
                AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
                AbstractObjectNormalizer::SKIP_UNINITIALIZED_VALUES => true,
            ]);
            $this->loggedPayload = true;
        }

        return $record;
    }

    #[Required]
    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        $this->normalizer = $normalizer;
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $prevEnvelope = $this->currentEnvelope;

        if (null !== $prevEnvelope) {
            $envelope = $envelope
                ->withoutAll(OriginTransportMessageIdStamp::class)
                ->with(new OriginTransportMessageIdStamp($prevEnvelope->last(TransportMessageIdStamp::class)?->getId()))
            ;
        }

        $this->currentEnvelope = $envelope;
        $this->loggedPayload = false;

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->currentEnvelope = $prevEnvelope;
            $this->loggedPayload = false;
        }
    }
}
