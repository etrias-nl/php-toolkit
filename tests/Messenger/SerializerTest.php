<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Messenger\Serializer\DeflateSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * @internal
 */
final class SerializerTest extends TestCase
{
    public function testDeflate(): void
    {
        $deflateSerializer = new DeflateSerializer();
        $message = (object) ['typeString' => 'string', 'typeInt' => 1, 'typeFloat' => 1.1, 'typeBool' => true, 'typeNull' => null, 'typeArray' => ['nested'], 'typeObject' => (object) ['nested' => true]];
        $messageIdStamp = new TransportMessageIdStamp('ID');
        $envelope = Envelope::wrap($message, [$messageIdStamp]);
        $encodedEnvelope = $deflateSerializer->encode($envelope);

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

        $assertDecodedEnvelope($deflateSerializer->decode($encodedEnvelope));

        $compressedFixture = \dirname(__DIR__).'/Fixtures/Messenger/envelope_compressed.php';
        $uncompressedFixture = \dirname(__DIR__).'/Fixtures/Messenger/envelope_uncompressed.php';

        // file_put_contents($compressedFixture, '<?php return '.var_export($encodedEnvelope, true).';');
        // file_put_contents($uncompressedFixture, '<?php return '.var_export((new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer())->encode($envelope), true).';');

        $assertDecodedEnvelope($deflateSerializer->decode(require $compressedFixture));
        $assertDecodedEnvelope($deflateSerializer->decode(require $uncompressedFixture));
    }
}
