<?php

declare(strict_types=1);

namespace JostBaron\FalWebdav\Driver;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class WebdavClientFactory
{
    private LoggerInterface $logger;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private UriFactoryInterface $uriFactory;
    private ClientInterface $httpClient;

    public function __construct(
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        UriFactoryInterface $uriFactory,
        ClientInterface $httpClient
    ) {
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->uriFactory = $uriFactory;
        $this->httpClient = $httpClient;

        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    public function createWebdavClient(string $webdavUrl, string $publicUrl): WebdavClient
    {
        return new WebdavClient(
            $this->logger,
            $this->requestFactory,
            $this->streamFactory,
            $this->uriFactory,
            $this->httpClient,
            $webdavUrl,
            $publicUrl
        );
    }
}
