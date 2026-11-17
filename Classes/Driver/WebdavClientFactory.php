<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Driver;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

class WebdavClientFactory
{
    public function __construct(
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UriFactoryInterface $uriFactory,
        private readonly ClientInterface $httpClient,
    ) {
    }

    public function createWebdavClient(?string $webdavUrl, ?string $publicUrl): WebdavClient
    {
        return new WebdavClient(
            $this->requestFactory,
            $this->streamFactory,
            $this->uriFactory,
            $this->httpClient,
            $webdavUrl ?? 'https://example.com/dav/',
            $publicUrl ?? 'https://example.org/files/'
        );
    }
}
