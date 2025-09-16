<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Soap;

use Soap\ExtSoapEngine\Wsdl\WsdlProvider;
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
            $this->filesystem->dumpFile($cacheFile, ($this->loader)($location));
        }

        return $cacheFile;
    }
}
