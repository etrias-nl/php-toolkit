<?php

declare(strict_types=1);

namespace Etrias\PhpToolkit\Flysystem;

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use League\Flysystem\FilesystemAdapter;

final class RuntimeAdapterFactory
{
    private const string TYPE_AZURE_BLOB = 'azure+blob';

    public static function create(string $dsn, FilesystemAdapter $default, string $prefix = ''): FilesystemAdapter
    {
        if (false === filter_var($dsn, FILTER_VALIDATE_URL)) {
            return $default;
        }

        $urlParts = parse_url($dsn);
        $queryParts = [];
        parse_str($urlParts['query'] ?? '', $queryParts);
        $protocol = $urlParts['scheme'];
        $urlParts['scheme'] = filter_var($queryParts['ssl'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 'https' : 'http';
        $urlParts['user'] = urldecode($urlParts['user']);
        $urlParts['pass'] = urldecode($urlParts['pass']);
        $directory = urldecode(ltrim($urlParts['path'], '/'));

        switch ($protocol) {
            case self::TYPE_AZURE_BLOB:
                $connectionString = \sprintf('DefaultEndpointsProtocol=%s;AccountName=%s;AccountKey=%s;EndpointSuffix=%s', $urlParts['scheme'], $urlParts['user'], $urlParts['pass'], $urlParts['host']);
                $blobServiceClient = BlobServiceClient::fromConnectionString($connectionString);

                return new AzureBlobStorageAdapter($blobServiceClient->getContainerClient($directory), $prefix);
        }

        throw new \RuntimeException('Unsupported filesystem DSN protocol: '.$urlParts['scheme']);
    }
}
