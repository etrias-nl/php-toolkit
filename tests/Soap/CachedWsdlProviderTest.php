<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Tests\Soap;

use Etrias\PhpToolkit\Soap\CachedWsdlProvider;
use PHPUnit\Framework\TestCase;
use Soap\Wsdl\Exception\UnloadableWsdlException;
use Soap\Wsdl\Loader\WsdlLoader;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @internal
 */
final class CachedWsdlProviderTest extends TestCase
{
    private const string VALID_WSDL = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <wsdl:definitions xmlns:wsdl="http://schemas.xmlsoap.org/wsdl/" name="Test">
            <wsdl:service name="Test"/>
        </wsdl:definitions>
        XML;

    private const string LOCATION = 'https://example.test/service.wsdl';

    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/php-toolkit-wsdl-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }

    public function testCachesValidWsdlOnce(): void
    {
        $loader = new class(self::VALID_WSDL) implements WsdlLoader {
            public int $calls = 0;

            public function __construct(private readonly string $wsdl) {}

            public function __invoke(string $location): string
            {
                ++$this->calls;

                return $this->wsdl;
            }
        };

        $provider = new CachedWsdlProvider($loader, new Filesystem(), $this->cacheDir, false);

        $file = $provider(self::LOCATION);

        self::assertFileExists($file);
        self::assertStringContainsString('definitions', (string) file_get_contents($file));

        self::assertSame($file, $provider(self::LOCATION));
        self::assertSame(1, $loader->calls, 'A cached WSDL must be loaded only once.');
    }

    public function testDoesNotCacheEmptyWsdl(): void
    {
        $provider = new CachedWsdlProvider($this->loader(''), new Filesystem(), $this->cacheDir, false);

        try {
            $provider(self::LOCATION);
            self::fail('Expected an UnloadableWsdlException.');
        } catch (UnloadableWsdlException) {
        }

        self::assertFileDoesNotExist($this->cacheDir.'/'.md5(self::LOCATION).'.wsdl');
    }

    public function testDoesNotCacheMalformedWsdl(): void
    {
        $provider = new CachedWsdlProvider($this->loader('<html><body>404 Not Found</body></html>'), new Filesystem(), $this->cacheDir, false);

        try {
            $provider(self::LOCATION);
            self::fail('Expected an UnloadableWsdlException.');
        } catch (UnloadableWsdlException) {
        }

        self::assertFileDoesNotExist($this->cacheDir.'/'.md5(self::LOCATION).'.wsdl');
    }

    private function loader(string $wsdl): WsdlLoader
    {
        return new class($wsdl) implements WsdlLoader {
            public function __construct(private readonly string $wsdl) {}

            public function __invoke(string $location): string
            {
                return $this->wsdl;
            }
        };
    }
}
