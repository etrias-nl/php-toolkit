<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\QueryMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * @internal
 */
final class QueryMessageTest extends TestCase
{
    public function testFromEnvelope(): void
    {
        $envelope = Envelope::wrap(new \stdClass(), [new HandledStamp('result', 'handler')]);

        self::assertSame('result', QueryMessage::fromEnvelope($envelope));
    }

    public function testFromEnvelopeWithoutResult(): void
    {
        $envelope = Envelope::wrap(new \stdClass());

        $this->expectException(\LogicException::class);

        QueryMessage::fromEnvelope($envelope);
    }

    public function testFromEnvelopeWithMultipleResults(): void
    {
        $envelope = Envelope::wrap(new \stdClass(), [new HandledStamp('result', 'handler'), new HandledStamp('result2', 'handler')]);

        $this->expectException(\LogicException::class);

        QueryMessage::fromEnvelope($envelope);
    }
}
