<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Cache;

use Etrias\PhpToolkit\Cache\CacheInfo;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CacheInfoTest extends TestCase
{
    public function testInfo(): void
    {
        $info = new CacheInfo('key');

        self::assertSame('key', $info->key);
        self::assertNull($info->ttl);
        self::assertSame([], $info->tags);

        $info = new CacheInfo('key', 123, ['tag1', 'tag2']);

        self::assertSame('key', $info->key);
        self::assertSame(123, $info->ttl);
        self::assertSame(['tag1', 'tag2'], $info->tags);

        $info = new CacheInfo('key', $ttl = new \DateTime());

        self::assertSame('key', $info->key);
        self::assertSame($ttl, $info->ttl);
        self::assertSame([], $info->tags);

        $info = new CacheInfo('key', $ttl = new \DateInterval('PT1H'));

        self::assertSame('key', $info->key);
        self::assertSame($ttl, $info->ttl);
        self::assertSame([], $info->tags);

        $info = new CacheInfo('key', null, ['tag1', 'tag2']);

        self::assertSame('key', $info->key);
        self::assertNull($info->ttl);
        self::assertSame(['tag1', 'tag2'], $info->tags);
    }

    public function testArray(): void
    {
        $info = new CacheInfo($key = [1, [2.0, 'foo']]);

        self::assertSame(hash('xxh128', serialize($key)), $info->key);
        self::assertSame('a422f6dc0c83c49d327cdeab5651e8b9', $info->key);
    }

    public function testObject(): void
    {
        $info = new CacheInfo($key = (object) [1, [2.0, 'foo']]);

        self::assertSame(hash('xxh128', serialize($key)), $info->key);
        self::assertSame('084555216497935884c30637186765a9', $info->key);
    }

    public function testMakeTag(): void
    {
        self::assertSame('_Foo_', CacheInfo::makeTag('_Foo_'));
        self::assertSame('__Foo_____bar__', CacheInfo::makeTag('__Foo__', '__bar__'));
        self::assertSame('_Foo-_-bar__-foo--bar--', CacheInfo::makeTag('_Foo\\', '\\bar_', '-foo\\\\bar--'));
        self::assertSame('1_2_3', CacheInfo::makeTag(1, 2, 3));
    }
}
