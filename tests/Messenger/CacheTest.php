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
        $handlerCount = 0;
        $bus = new MessageBus([$cacheMiddleware, new HandleMessageMiddleware(new HandlersLocator(['*' => [static function () use (&$handlerCount): string {
            ++$handlerCount;

            return 'handler result';
        }]]))]);
        $message = (object) ['test' => true];

        $cacheInfoProvider->expects(self::exactly(4))
            ->method('get')
            ->with($message)
            ->willReturn(
                null,
                $info = new CacheInfo('key', new \DateTime('tomorrow'), ['tag']),
                $info,
                $info,
            )
        ;
        $bus->dispatch($message);

        self::assertSame(1, $handlerCount);

        $envelope = $bus->dispatch($message);
        $cacheItem = $cache->getItem('key');
        $cachedResult = $cacheItem->get();

        self::assertSame(2, $handlerCount);
        self::assertSame(['tags' => ['tag' => 'tag']], $cacheItem->getMetadata());
        self::assertIsArray($cachedResult);
        self::assertCount(1, $cachedResult);
        self::assertContainsOnlyInstancesOf(HandledStamp::class, $cachedResult);
        self::assertSame('handler result', $cachedResult[0]->getResult());
        self::assertSame('handler result', $envelope->last(HandledStamp::class)?->getResult());

        $bus->dispatch($message);

        self::assertSame(2, $handlerCount);

        $cache->invalidateTags(['tag']);
        $bus->dispatch($message);

        self::assertSame(3, $handlerCount);
    }
}
