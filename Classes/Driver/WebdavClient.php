<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Driver;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class WebdavClient
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UriFactoryInterface $uriFactory,
        private readonly ClientInterface $httpClient,
        private readonly string $webdavUrl,
        private readonly string $publicUrlPrefix,
    ) {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    public function createFolder(string $path): ?string
    {
        $pathParts = \explode('/', \trim($path, '/ '));
        $currentPath = '/';
        foreach ($pathParts as $pathPart) {
            $currentPath .= $pathPart . '/';
            $response = $this->executeRequest(
                $this->requestFactory->createRequest(
                    'MKCOL',
                    $this->uriFactory->createUri($this->getWebdavPath($currentPath)),
                )
            );

            if (null === $response) {
                return null;
            }
            if (201 !== $response->getStatusCode() && 405 !== $response->getStatusCode()) {
                return null;
            }
        }

        return $currentPath;
    }

    public function pathExists(string $path): bool
    {
        $response = $this->executeRequest(
            $this->requestFactory->createRequest('HEAD', $this->getWebdavPath($path))
        );

        return null !== $response && 200 === $response->getStatusCode();
    }

    public function delete(string $path): bool
    {
        $response = $this->executeRequest(
            $this->requestFactory->createRequest('DELETE', $this->getWebdavPath($path))
        );

        return null !== $response && 204 === $response->getStatusCode();
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

        // Like TYPO3's LocalDriver, a folder that cannot be read (e.g. because it does not exist) is empty.
        if (null === $response || 207 !== $response->getStatusCode()) {
            return true;
        }

        $results = $this->splitMultiStatusResponse($response);
        if ([] === $results || 200 !== $results[0]->getStatusCode()) {
            return true;
        }

        // The first entry is the folder itself, every further entry is a child.
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
        if (null === $response) {
            return false;
        }
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
        if (null === $response) {
            return false;
        }
        if (201 !== $response->getStatusCode() && 204 !== $response->getStatusCode()) {
            $this->logger->error(
                'Moving failed',
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
        if (null === $response || 200 !== $response->getStatusCode()) {
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

        $request = $this->requestFactory
            ->createRequest('PROPFIND', $this->getWebdavPath($path))
            ->withHeader('Content-Type', 'application/xml; charset="utf-8"')
            ->withHeader('Depth', '0')
            ->withBody($this->streamFactory->createStream($requestBody));

        $response = $this->executeRequest($request);

        $remainingAttempts = 3;
        while (null !== $response && 300 <= $response->getStatusCode() && $response->getStatusCode() < 400) {
            $redirectUris = $response->getHeader('Location');
            if (0 === \count($redirectUris)) {
                throw new FileOperationErrorException(
                    'Got no "Location" header in 3xx HTTP response.',
                    1643218355
                );
            }
            $request = $request->withUri($this->uriFactory->createUri($redirectUris[0]));
            $response = $this->executeRequest($request);
            $remainingAttempts--;
            if (0 === $remainingAttempts) {
                break;
            }
        }

        // E.g. 404 if the resource does not exist.
        if (null === $response || 207 !== $response->getStatusCode()) {
            return [];
        }

        $results = $this->splitMultiStatusResponse($response);
        if (1 !== \count($results) || 200 !== $results[0]->getStatusCode()) {
            return [];
        }
        $response = $results[0];

        $xPath = new \DOMXPath($response->getNode()->ownerDocument);
        $xPath->registerNamespace('D', 'DAV:');

        return $this->parseProperties($xPath->query('//D:prop/*'));
    }

    /**
     * Gets the properties (see getProperties()) of the given collection and its descendants (only
     * direct children if not recursive) with a single request.
     *
     * @return array<string, array{ctime?: int, size?: int, mtime?: int, mimetype?: string}>|null
     *         Map of paths (including $path itself) to their properties, or null if the path cannot be
     *         listed, e.g. because it does not exist.
     */
    public function getDescendantProperties(string $path, bool $recursive): ?array
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
                ->withHeader('Depth', $recursive ? 'infinity' : '1')
                ->withBody($this->streamFactory->createStream($requestBody))
        );

        if (null === $response || 207 !== $response->getStatusCode()) {
            return null;
        }

        $results = $this->splitMultiStatusResponse($response);
        if ([] === $results || 200 !== $results[0]->getStatusCode()) {
            return null;
        }

        $propertiesByPath = [];
        foreach ($results as $result) {
            // Servers report found and missing properties in separate <propstat> elements (status 200
            // and 404), in no particular order. Only use the found ones.
            $responseNode = $result->getNode()->parentNode->parentNode;
            $xPath = new \DOMXPath($responseNode->ownerDocument);
            $xPath->registerNamespace('D', 'DAV:');
            $propertyNodes = $xPath->query(
                './D:propstat[contains(D:status, " 200 ")]/D:prop/*',
                $responseNode
            );
            $propertiesByPath[$result->getPath()] = $this->parseProperties($propertyNodes);
        }

        return $propertiesByPath;
    }

    /**
     * Lists the given collection and its descendants (only direct children if not recursive).
     *
     * @return array<string, string>|null Map of paths (including $path itself) to "file" or "directory",
     *                                    or null if the path cannot be listed, e.g. because it does not exist.
     */
    public function getDescendantPaths(string $path, bool $recursive): ?array
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

        if (null === $response || 207 !== $response->getStatusCode()) {
            return null;
        }

        $results = $this->splitMultiStatusResponse($response);
        if ([] === $results || 200 !== $results[0]->getStatusCode()) {
            return null;
        }

        $xPath = null;
        $pathsToTypesMap = [];
        foreach ($results as $result) {
            if (null === $xPath) {
                $xPath = new \DOMXPath($result->getNode()->ownerDocument);
                $xPath->registerNamespace('D', 'DAV:');
            }

            $contentTypeNodes = $xPath->query('.//D:getcontenttype/text()', $result->getNode());
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

    /**
     * @return array{ctime?: int, size?: int, mtime?: int, mimetype?: string}
     */
    private function parseProperties(\DOMNodeList $propertyNodes): array
    {
        $properties = [];
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

    public function getPublicUrl(string $path): string
    {
        return \rtrim($this->publicUrlPrefix, '/') . '/' . \ltrim($path, '/');
    }

    private function isSuccessful(?ResponseInterface $response): bool
    {
        return null !== $response && $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    private function isError(?ResponseInterface $response): bool
    {
        return null === $response || $response->getStatusCode() >= 500;
    }

    private function executeRequest(RequestInterface $request): ?ResponseInterface
    {
        try {
            $response = $this->httpClient->sendRequest($request);
            $this->logger->debug(
                'Got WebDAV response',
                [
                    'request' => [
                        'method' => $request->getMethod(),
                        'uri' => (string)$request->getUri(),
                        'headers' => $request->getHeaders(),
                        'body' => (string)$request->getBody(),
                    ],
                    'response' => [
                        'status' => $response->getStatusCode(),
                        'reason' => $response->getReasonPhrase(),
                        'headers' => $response->getHeaders(),
                        'body' => (string)$response->getBody(),
                    ],
                ]
            );

            if ($this->isError($response)) {
                $this->logger->error(
                    'WebDAV request failed:',
                    [
                        'request' => [
                            'method' => $request->getMethod(),
                            'uri' => (string)$request->getUri(),
                            'headers' => $request->getHeaders(),
                            'body' => (string)$request->getBody(),
                        ],
                        'response' => [
                            'status' => $response->getStatusCode(),
                            'reason' => $response->getReasonPhrase(),
                            'headers' => $response->getHeaders(),
                            'body' => (string)$response->getBody(),
                        ]
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
                        'method' => $request->getMethod(),
                        'uri' => (string)$request->getUri(),
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
                        'method' => $request->getMethod(),
                        'uri' => (string)$request->getUri(),
                        'headers' => $request->getHeaders(),
                        'body' => (string)$request->getBody(),
                    ],
                ]
            );
            return null;
        }
    }

    /**
     * @return array<MultiStatusResponse>
     */
    private function splitMultiStatusResponse(ResponseInterface $response): array
    {
        if (207 !== $response->getStatusCode()) {
            $this->logger->critical(
                'Should split multi-status response that does not have status code 207.',
                [
                    'response' => [
                        'status' => $response->getStatusCode(),
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
            $statusNodes = $xPath->query('./D:propstat/D:status/text()', $xmlResponse);
            $propNodes = $xPath->query('./D:propstat/D:prop', $xmlResponse);

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
            if (!\str_contains($responseUri, '://')) {
                // This is only the path.
                $resourceUri = $webdavUrl->withPath($responseUri);
            } else {
                $resourceUri = $this->uriFactory->createUri($responseUri);
            }
            // hrefs are URL-encoded (RFC 4918, section 8.3.1), FAL identifiers are not. Decode only after
            // removing the base URL, which is compared in its encoded form. rawurldecode() keeps "+".
            $resourcePath = '/' . \ltrim(
                \rawurldecode(\substr((string)$resourceUri, \strlen((string)$webdavUrl))),
                '/'
            );

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
