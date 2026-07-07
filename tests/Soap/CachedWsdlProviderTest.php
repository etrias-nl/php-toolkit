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

    private Filesystem $filesystem;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->cacheDir = sys_get_temp_dir().'/php-toolkit-wsdl-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->cacheDir);
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

        $provider = new CachedWsdlProvider($loader, $this->filesystem, $this->cacheDir, false);
        $location = 'https://example.test/service.wsdl';

        $file = $provider($location);

        self::assertFileExists($file);
        self::assertStringContainsString('definitions', (string) file_get_contents($file));
        self::assertSame(1, $loader->calls);

        self::assertSame($file, $provider($location));
        self::assertSame(1, $loader->calls, 'A cached WSDL must not be reloaded.');
    }

    public function testDoesNotCacheEmptyWsdl(): void
    {
        $provider = new CachedWsdlProvider($this->loader(''), $this->filesystem, $this->cacheDir, false);

        try {
            $provider('https://example.test/service.wsdl');
            self::fail('Expected an UnloadableWsdlException.');
        } catch (UnloadableWsdlException) {
        }

        self::assertSame([], glob($this->cacheDir.'/*.wsdl') ?: []);
    }

    public function testDoesNotCacheMalformedWsdl(): void
    {
        $provider = new CachedWsdlProvider($this->loader('<html><body>404 Not Found</body></html>'), $this->filesystem, $this->cacheDir, false);

        try {
            $provider('https://example.test/service.wsdl');
            self::fail('Expected an UnloadableWsdlException.');
        } catch (UnloadableWsdlException) {
        }

        self::assertSame([], glob($this->cacheDir.'/*.wsdl') ?: []);
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
