<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Soap;

use Soap\ExtSoapEngine\Wsdl\WsdlProvider;
use Soap\Wsdl\Exception\UnloadableWsdlException;
use Soap\Wsdl\Loader\WsdlLoader;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsAlias(WsdlProvider::class)]
final class CachedWsdlProvider implements WsdlProvider
{
    public function __construct(
        private readonly WsdlLoader $loader,
        private readonly Filesystem $filesystem,
        #[Autowire(value: '%kernel.cache_dir%/wsdl')]
        private readonly string $cacheDir,
        #[Autowire(expression: "container.getParameter('kernel.environment') == 'test'")]
        private readonly bool $test,
    ) {}

    public function __invoke(string $location): string
    {
        $cacheFile = $this->cacheDir.'/'.md5($location).'.wsdl';

        if (!$this->filesystem->exists($cacheFile) || $this->test) {
            $wsdl = ($this->loader)($location);

            // Never cache an empty or malformed WSDL: the cache file has no TTL and is only
            // refetched when missing, so a bad response (e.g. an upstream 404) would otherwise
            // be served on every call until the cache is cleared by hand.
            if ('' === trim($wsdl)) {
                throw UnloadableWsdlException::noContentAt($location);
            }
            if (!$this->isWellFormedWsdl($wsdl)) {
                throw UnloadableWsdlException::fromLocation($location);
            }

            $this->filesystem->dumpFile($cacheFile, $wsdl);
        }

        return $cacheFile;
    }

    private function isWellFormedWsdl(string $wsdl): bool
    {
        if ('' === $wsdl) {
            return false;
        }

        $useInternalErrors = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            $rootElement = $document->loadXML($wsdl, LIBXML_NONET) ? $document->documentElement?->localName : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternalErrors);
        }

        return 'definitions' === $rootElement || 'description' === $rootElement;
    }
}
