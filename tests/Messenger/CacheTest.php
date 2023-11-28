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
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;

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
        $bus = new MessageBus([$cacheMiddleware, new HandleMessageMiddleware(new HandlersLocator(['*' => [static fn (): string => 'handler result']]))]);
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

        $cachedResult = $item->get();

        self::assertIsArray($cachedResult);
        self::assertCount(1, $cachedResult);
        self::assertContainsOnlyInstancesOf(HandledStamp::class, $cachedResult);
        self::assertSame('handler result', $cachedResult[0]->getResult());
        self::assertSame('handler result', $envelope->last(HandledStamp::class)?->getResult());

        $bus->dispatch($message);

        self::assertFalse($cache->getItem('key_expired')->isHit());
    }
}
