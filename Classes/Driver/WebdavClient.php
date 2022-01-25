<?php

declare(strict_types=1);

namespace JostBaron\FalWebdav\Driver;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

class WebdavClient
{
    private LoggerInterface $logger;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private UriFactoryInterface $uriFactory;
    private ClientInterface $httpClient;

    private string $webdavUrl;
    private string $publicUrlPrefix;

    public function __construct(
        LoggerInterface $logger,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        UriFactoryInterface $uriFactory,
        ClientInterface $httpClient,
        string $webdavUrl,
        string $publicUrlPrefix
    ) {
        $this->logger = $logger;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->uriFactory = $uriFactory;
        $this->httpClient = $httpClient;
        $this->webdavUrl = $webdavUrl;
        $this->publicUrlPrefix = $publicUrlPrefix;
    }

    public function createFolder(string $path): ?string
    {
        $pathParts = \explode('/', \trim($path, '/ '));
        $currentPath = '';
        foreach ($pathParts as $pathPart) {
            $currentPath .= '/' . $pathPart;
            $response = $this->executeRequest(
                $this->requestFactory->createRequest(
                    'MKCOL',
                    $this->uriFactory->createUri($this->getWebdavPath($currentPath)),
                )
            );

            if (!$this->isSuccessful($response)) {
                return null;
            }
        }

        return '/' . \trim($path, '/') . '/';
    }

    public function pathExists(string $path): bool
    {
        $response = $this->executeRequest(
            $this->requestFactory->createRequest('GET', $this->getWebdavPath($path))
        );

        return 200 === $response->getStatusCode();
    }

    public function delete(string $path): bool
    {
        $response = $this->executeRequest(
            $this->requestFactory->createRequest('DELETE', $this->getWebdavPath($path))
        );

        return 204 === $response->getStatusCode();
    }

    public function isFolderEmpty(string $path): bool
    {
        $emptyRequestBody = <<<'BODY'
<?xml version="1.0" encoding="utf-8" ?>
<propfind xmlns="DAV:">
<propname/>
</propfind>
BODY;
        $emptyRequestBody = \trim($emptyRequestBody);

        $response = $this->executeRequest(
            $this->requestFactory
                ->createRequest('PROPFIND', $this->getWebdavPath($path))
                ->withHeader('Content-Type', 'application/xml; charset="utf-8"')
                ->withHeader('Depth', '1')
                ->withBody($this->streamFactory->createStream($emptyRequestBody))
        );

        $results = $this->splitMultiStatusResponse($response);
        return 1 === \count($results);
    }

    public function uploadFile(string $path, $contentStream): bool
    {
        $response = $this->executeRequest(
            $this->requestFactory->createRequest('PUT', $this->getWebdavPath($path))
                ->withBody($this->streamFactory->createStreamFromResource($contentStream))
        );
        return $this->isSuccessful($response);
    }

    public function copy(string $oldPath, string $newPath): bool
    {
        $response = $this->executeRequest(
            $this->requestFactory->createRequest('COPY', $this->getWebdavPath($oldPath))
                ->withHeader('Destination', $this->getWebdavPath($newPath))
                ->withHeader('Depth', 'infinity')
        );
        if (201 !== $response->getStatusCode() && 204 !== $response->getStatusCode()) {
            $this->logger->error(
                'Copying failed',
                [
                    'source' => $oldPath,
                    'target' => $newPath,
                ]
            );
            return false;
        }
        return true;
    }

    public function move(string $oldPath, string $newPath): bool
    {
        $response = $this->executeRequest(
            $this->requestFactory->createRequest('MOVE', $this->getWebdavPath($oldPath))
                ->withHeader('Destination', $this->getWebdavPath($newPath))
                ->withHeader('Depth', 'infinity')
        );
        if (201 !== $response->getStatusCode() && 204 !== $response->getStatusCode()) {
            $this->logger->error(
                'Copying failed',
                [
                    'source' => $oldPath,
                    'target' => $newPath,
                ]
            );
            return false;
        }
        return true;
    }

    public function get(string $path): string
    {
        $response = $this->executeRequest(
            $this->requestFactory->createRequest('GET', $this->getWebdavPath($path))
        );
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Could not get file contents.', 1643139962);
        }

        return (string)$response->getBody();
    }

    public function getProperties(string $path): array
    {
        $requestBody = <<<'BODY'
<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:">
    <D:prop xmlns:D="DAV:">
        <D:creationdate/>
        <D:getcontentlength/>
        <D:getlastmodified/>
        <D:getcontenttype/>
    </D:prop>
</D:propfind>
BODY;
        $requestBody = \trim($requestBody);

        $response = $this->executeRequest(
            $this->requestFactory
                ->createRequest('PROPFIND', $this->getWebdavPath($path))
                ->withHeader('Content-Type', 'application/xml; charset="utf-8"')
                ->withHeader('Depth', '0')
                ->withBody($this->streamFactory->createStream($requestBody))
        );

        $results = $this->splitMultiStatusResponse($response);
        if (1 !== \count($results) || 200 !== $results[0]->getStatusCode()) {
            return [];
        }
        $response = $results[0];

        $xPath = new \DOMXPath($response->getNode()->ownerDocument);
        $xPath->registerNamespace('D', 'DAV:');

        $properties = [];
        $propertyNodes = $xPath->query('//D:prop/*');
        for ($i = 0; $i < $propertyNodes->length; $i++) {
            $propertyNode = $propertyNodes->item($i);
            switch ($propertyNode->localName) {
                case 'creationdate':
                    $properties['ctime'] = (new \DateTimeImmutable($propertyNode->textContent))->getTimestamp();
                    break;
                case 'getcontentlength':
                    $properties['size'] = (int)$propertyNode->textContent;
                    break;
                case 'getlastmodified':
                    $parsedTime = \strtotime($propertyNode->textContent);
                    if (false !== $parsedTime) {
                        $properties['mtime'] = $parsedTime;
                    }
                    break;
                case 'getcontenttype':
                    $properties['mimetype'] = $propertyNode->textContent;
                    break;
            }
        }

        return $properties;
    }

    public function getDescendantPaths(string $path, bool $recursive): array
    {
        $requestBody = <<<'BODY'
<?xml version="1.0" encoding="utf-8" ?>
<D:propfind xmlns:D="DAV:">
    <D:prop xmlns:D="DAV:">
        <D:getcontenttype/>
    </D:prop>
</D:propfind>
BODY;
        $requestBody = \trim($requestBody);

        $response = $this->executeRequest(
            $this->requestFactory
                ->createRequest('PROPFIND', $this->getWebdavPath($path))
                ->withHeader('Content-Type', 'application/xml; charset="utf-8"')
                ->withHeader('Depth', $recursive ? 'infinity' : '1')
                ->withBody($this->streamFactory->createStream($requestBody))
        );

        $results = $this->splitMultiStatusResponse($response);
        if (1 !== \count($results) || 200 !== $results[0]->getStatusCode()) {
            return [];
        }

        $xPath = null;
        $pathsToTypesMap = [];
        foreach ($results as $result) {
            if (null === $xPath) {
                $xPath = new \DOMXPath($result->getNode()->ownerDocument);
                $xPath->registerNamespace('D', 'DAV:');
            }

            $contentTypeNodes = $xPath->query('D:getcontenttype/text()');
            $pathType = 'file';
            if (0 !== $contentTypeNodes->length) {
                if ('httpd/unix-directory' === $contentTypeNodes->item(0)->textContent) {
                    $pathType = 'directory';
                }
            }

            $pathsToTypesMap[$result->getPath()] = $pathType;
        }

        return $pathsToTypesMap;
    }

    public function getPublicUrl(string $path): UriInterface
    {
        return $this->uriFactory->createUri(
            \rtrim($this->publicUrlPrefix, '/') . '/' . \ltrim($path, '/')
        );
    }

    private function isSuccessful(ResponseInterface $response): bool
    {
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    private function isError(ResponseInterface $response): bool
    {
        return $response->getStatusCode() >= 500;
    }

    private function isNotFound(ResponseInterface $response): bool
    {
        return 404 === $response->getStatusCode();
    }

    private function executeRequest(RequestInterface $request): ?ResponseInterface
    {
        try {
            $response = $this->httpClient->sendRequest($request);
            $this->logger->debug(
                'Got WebDAV response',
                [
                    'request' => [
                        'headers' => $request->getHeaders(),
                        'body' => (string)$request->getBody(),
                    ],
                    'response' => [
                        'headers' => $request->getHeaders(),
                        'body' => (string)$request->getBody(),
                    ],
                ]
            );

            if ($this->isError($response)) {
                $this->logger->error(
                    'WebDAV request failed:',
                    [
                        'request' => [
                            'headers' => $request->getHeaders(),
                            'body' => (string)$request->getBody(),
                        ],
                        'response' => [
                            'headers' => $request->getHeaders(),
                            'body' => (string)$request->getBody(),
                        ],
                    ]
                );
            }

            return $response;

        } catch (RequestExceptionInterface $requestException) {
            $this->logger->critical(
                'Invalid request!',
                [
                    'exception' => $requestException,
                    'request' => [
                        'headers' => $request->getHeaders(),
                        'body' => (string)$request->getBody(),
                    ],
                ]
            );
            // This is a bug, rethrow the exception to crash the application (and force fixing the bug).
            throw $requestException;
        } catch (ClientExceptionInterface $clientException) {
            // Not a bug, something else failed.
            $this->logger->error(
                'Request failed.',
                [
                    'exception' => $clientException,
                    'request' => [
                        'headers' => $request->getHeaders(),
                        'body' => (string)$request->getBody(),
                    ],
                ]
            );
            return null;
        }
    }

    /**
     * @param ResponseInterface $response
     * @return array<MultiStatusResponse>
     */
    private function splitMultiStatusResponse(ResponseInterface $response): array
    {
        if (207 !== $response->getStatusCode()) {
            $this->logger->critical(
                'Should split multi-status response that does not have status code 207.',
                [
                    'response' => [
                        'headers' => $response->getHeaders(),
                        'body' => (string)$response->getBody(),
                    ],
                ]
            );
            throw new \InvalidArgumentException('Response is not a Multi-Status response.', 1643130371);
        }

        $responseBody = (string)$response->getBody();

        $domDocument = new \DOMDocument('1.0', 'UTF-8');
        $loadedSuccessfully = $domDocument->loadXML($responseBody);

        if (true !== $loadedSuccessfully) {
            $this->logger->error(
                'Got invalid XML in response.',
                [
                    'response' => [
                        'headers' => $response->getHeaders(),
                        'body' => $responseBody,
                    ],
                ]
            );
        }

        $xPath = new \DOMXPath($domDocument);
        $xPath->registerNamespace('D', 'DAV:');
        $xmlResponses = $xPath->query('/D:multistatus/D:response');

        $result = [];
        foreach ($xmlResponses as $xmlResponse) {
            $uriNodes = $xPath->query('./D:href/text()', $xmlResponse);
            $statusNodes = $xPath->query('./D:status/text()', $xmlResponse);
            $propNodes = $xPath->query('./D:prop');

            if (0 === $uriNodes->length) {
                $this->logger->error(
                    'Got multistatus response without URI.',
                    [
                        'responseNode' => $domDocument->saveXML($xmlResponse),
                    ]
                );
                continue;
            }
            if (0 === $statusNodes->length) {
                $this->logger->error(
                    'Got multistatus response without HTTP Status.',
                    [
                        'responseNode' => $domDocument->saveXML($xmlResponse),
                    ]
                );
                continue;
            }
            if (0 === $propNodes->length) {
                $this->logger->error(
                    'Got multistatus response without <prop> nodes.',
                    [
                        'responseNode' => $domDocument->saveXML($xmlResponse),
                    ]
                );
                continue;
            }

            $responseUri = \trim($uriNodes->item(0)->textContent);
            $httpStatusLine = \trim($statusNodes->item(0)->textContent);

            if (1 !== \preg_match('#^HTTP/[^ ]+ (\d{3}) .+$#', $httpStatusLine, $matches)) {
                $this->logger->error(
                    'Could not parse HTTP status line.',
                    [
                        'responseNode' => $domDocument->saveXML($xmlResponse),
                    ]
                );
                continue;
            }

            $statusCode = (int)$matches[1];

            $webdavUrl = $this->uriFactory->createUri($this->webdavUrl);
            if (false === \strpos($responseUri, '://')) {
                // This is only the path.
                $resourceUri = $webdavUrl->withPath($responseUri);
            } else {
                $resourceUri = $this->uriFactory->createUri($responseUri);
            }
            $resourcePath = '/' . \ltrim(\substr((string)$resourceUri, \strlen((string)$webdavUrl)), '/');

            $result[] = new MultiStatusResponse(
                $resourcePath,
                $statusCode,
                $propNodes->item(0)
            );
        }

        return $result;
    }

    private function getWebdavPath(string $path): string
    {
        return $this->concatenatePaths($this->webdavUrl, $path);
    }

    private function concatenatePaths(string $baseUrl, string $path): string
    {
        return \rtrim($baseUrl, '/') . '/' . \ltrim($path, '/');
    }
}
