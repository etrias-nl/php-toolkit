<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Messenger;

use Etrias\PhpToolkit\Cache\CacheInfo;
use Etrias\PhpToolkit\Cache\CacheInfoProvider;
use Etrias\PhpToolkit\Messenger\MessageCache;
use Etrias\PhpToolkit\Messenger\Middleware\CacheMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;

/**
 * @internal
 */
final class CacheTest extends TestCase
{
    public function testMiddleware(): void
    {
        $cache = new TagAwareAdapter(new ArrayAdapter());
        $cacheInfoProvider = $this->createMock(CacheInfoProvider::class);
        $cacheMiddleware = new CacheMiddleware(new MessageCache($cache, $cacheInfoProvider));
        $bus = new MessageBus([$cacheMiddleware]);
        $message = (object) ['test' => true];

        $cacheInfoProvider->expects(self::exactly(3))
            ->method('get')
            ->with($message)
            ->willReturn(
                null,
                new CacheInfo('key', null, ['tag']),
                new CacheInfo('key_expired', new \DateTime('yesterday')),
            )
        ;
        $bus->dispatch($message);

        self::assertFalse($cache->getItem('key')->isHit());

        $envelope = $bus->dispatch($message);
        $item = $cache->getItem('key');

        self::assertTrue($item->isHit());
        self::assertSame(['tags' => ['tag' => 'tag']], $item->getMetadata());

        $cachedEnvelope = $item->get();

        self::assertInstanceOf(Envelope::class, $cachedEnvelope);
        self::assertNotSame($envelope, $cachedEnvelope);
        self::assertSame((array) $message, (array) $cachedEnvelope->getMessage());

        $bus->dispatch($message);

        self::assertFalse($cache->getItem('key_expired')->isHit());
    }
}
