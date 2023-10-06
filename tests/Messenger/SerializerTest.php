<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Serializer\MarshallingSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * @internal
 */
final class SerializerTest extends TestCase
{
    public function testMarshalling(): void
    {
        $serializer = new MarshallingSerializer();
        $message = (object) ['typeString' => 'string', 'typeInt' => 1, 'typeFloat' => 1.1, 'typeBool' => true, 'typeNull' => null, 'typeArray' => ['nested'], 'typeObject' => (object) ['nested' => true]];
        $messageIdStamp = new TransportMessageIdStamp('ID');
        $encodedEnvelope = $serializer->encode(Envelope::wrap($message, [$messageIdStamp]));

        self::assertArrayHasKey('body', $encodedEnvelope);
        self::assertIsString($encodedEnvelope['body']);
        self::assertSame($encodedEnvelope['body'], hex2bin(bin2hex($encodedEnvelope['body'])));

        $assertDecodedEnvelope = static function (Envelope $decodedEnvelope) use ($message, $messageIdStamp): void {
            $decodedMessage = $decodedEnvelope->getMessage();

            self::assertSame($messageIdStamp->getId(), $decodedEnvelope->last(TransportMessageIdStamp::class)?->getId());
            self::assertSame($message->typeString, $decodedMessage->typeString);
            self::assertSame($message->typeInt, $decodedMessage->typeInt);
            self::assertSame($message->typeFloat, $decodedMessage->typeFloat);
            self::assertSame($message->typeBool, $decodedMessage->typeBool);
            self::assertSame($message->typeNull, $decodedMessage->typeNull);
            self::assertSame($message->typeArray, $decodedMessage->typeArray);
            self::assertSame($message->typeObject->nested, $decodedMessage->typeObject->nested);
        };

        $assertDecodedEnvelope($serializer->decode($encodedEnvelope));

        $encodedFixture = \dirname(__DIR__).'/Fixtures/Messenger/encoded_envelope.php';
        // file_put_contents($encodedFixture, '<?php return '.var_export($encodedEnvelope, true).';');

        $assertDecodedEnvelope($serializer->decode(require $encodedFixture));
    }
}
