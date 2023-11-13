<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Messenger\Middleware;

use Etrias\PhpToolkit\Messenger\Stamp\OriginTransportMessageIdStamp;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * @internal
 */
#[AsMonologProcessor]
final class LogProcessor
{
    public ?NormalizerInterface $normalizer = null;
    public ?Envelope $currentEnvelope = null;
    public bool $loggedPayload = false;

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null === $this->currentEnvelope) {
            return $record;
        }

        $record->extra['messenger']['id'] = $this->currentEnvelope->last(TransportMessageIdStamp::class)?->getId();
        $record->extra['messenger']['origin'] = $this->currentEnvelope->last(OriginTransportMessageIdStamp::class)?->id;

        if (!$this->loggedPayload && $record->level->isHigherThan(Level::Debug)) {
            if (null === $this->normalizer) {
                throw new \LogicException('Normalizer not set.');
            }

            // https://github.com/symfony/symfony/issues/52564
            $this->normalizer->normalize(null);

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
}
