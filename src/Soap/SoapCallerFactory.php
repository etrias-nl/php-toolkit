<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Soap;

use Phpro\SoapClient\Caller\EngineCaller;
use Psr\Http\Client\ClientInterface;
use Soap\ExtSoapEngine\ExtSoapEngineFactory;
use Soap\ExtSoapEngine\ExtSoapOptions;
use Soap\ExtSoapEngine\Wsdl\WsdlProvider;
use Soap\Psr18Transport\Psr18Transport;

final class SoapCallerFactory
{
    public function __construct(
        private readonly WsdlProvider $wsdlProvider,
    ) {}

    public function create(string $wsdl, ClientInterface $client, array $options = []): EngineCaller
    {
        $soapOptions = ExtSoapOptions::defaults($wsdl, $options);
        $soapOptions->disableWsdlCache();
        $soapOptions->withWsdlProvider($this->wsdlProvider);

        $engine = ExtSoapEngineFactory::fromOptionsWithTransport($soapOptions, Psr18Transport::createForClient($client));

        return new EngineCaller($engine);
    }
}
