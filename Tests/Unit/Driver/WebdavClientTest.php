<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Tests\Unit\Driver;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jbaron\FalWebdav\Driver\WebdavClient;
use Jbaron\FalWebdav\Tests\Unit\Fixtures\RecordingHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Http\UriFactory;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;

/**
 * @covers \Jbaron\FalWebdav\Driver\WebdavClient
 * @covers \Jbaron\FalWebdav\Driver\MultiStatusResponse
 */
class WebdavClientTest extends TestCase
{
    private const WEBDAV_URL = 'https://dav.example.com/dav/';
    private const PUBLIC_URL = 'https://files.example.com/file/';

    private RecordingHttpClient $httpClient;
    private WebdavClient $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient = new RecordingHttpClient();
        // Same PSR-17 implementations that TYPO3's DI container injects in production.
        $this->subject = new WebdavClient(
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
            new UriFactory(),
            $this->httpClient,
            self::WEBDAV_URL,
            self::PUBLIC_URL
        );
    }

    protected function tearDown(): void
    {
        self::assertFalse(
            $this->httpClient->hasPendingResponses(),
            'Not all prepared responses were consumed.'
        );
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // createFolder
    // ---------------------------------------------------------------------------------------------

    public function testCreateFolderSendsMkcolForEveryPathSegment(): void
    {
        $this->httpClient
            ->enqueue(new Response(201))
            ->enqueue(new Response(201));

        $result = $this->subject->createFolder('/foo/bar/');

        self::assertSame('/foo/bar/', $result);
        self::assertSame(
            [
                'MKCOL https://dav.example.com/dav/foo/',
                'MKCOL https://dav.example.com/dav/foo/bar/',
            ],
            $this->describeRequests()
        );
    }

    public function testCreateFolderTreatsAlreadyExistingParentsAsSuccess(): void
    {
        $this->httpClient
            ->enqueue(new Response(405))
            ->enqueue(new Response(201));

        self::assertSame('/existing/new/', $this->subject->createFolder('existing/new'));
    }

    public function testCreateFolderReturnsNullAndStopsOnFailure(): void
    {
        $this->httpClient->enqueue(new Response(409));

        self::assertNull($this->subject->createFolder('/foo/bar/'));
        self::assertCount(1, $this->httpClient->getRequests());
    }

    public function testCreateFolderReturnsNullIfServerIsUnreachable(): void
    {
        $this->httpClient->enqueue($this->createClientException());

        self::assertNull($this->subject->createFolder('/foo/'));
    }

    // ---------------------------------------------------------------------------------------------
    // pathExists
    // ---------------------------------------------------------------------------------------------

    public function testPathExistsUsesHeadRequest(): void
    {
        $this->httpClient->enqueue(new Response(200));

        self::assertTrue($this->subject->pathExists('/some/file.txt'));
        self::assertSame(['HEAD https://dav.example.com/dav/some/file.txt'], $this->describeRequests());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public function nonExistingStatusCodes(): array
    {
        return [
            'not found' => [404],
            'forbidden' => [403],
            'server error' => [500],
        ];
    }

    /**
     * @dataProvider nonExistingStatusCodes
     */
    public function testPathExistsReturnsFalseForNon200Responses(int $statusCode): void
    {
        $this->httpClient->enqueue(new Response($statusCode));

        self::assertFalse($this->subject->pathExists('/some/file.txt'));
    }

    public function testPathExistsReturnsFalseIfServerIsUnreachable(): void
    {
        $this->httpClient->enqueue($this->createClientException());

        self::assertFalse($this->subject->pathExists('/some/file.txt'));
    }

    // ---------------------------------------------------------------------------------------------
    // delete
    // ---------------------------------------------------------------------------------------------

    public function testDeleteReturnsTrueOnNoContent(): void
    {
        $this->httpClient->enqueue(new Response(204));

        self::assertTrue($this->subject->delete('/some/file.txt'));
        self::assertSame(['DELETE https://dav.example.com/dav/some/file.txt'], $this->describeRequests());
    }

    public function testDeleteReturnsFalseOnOtherResponses(): void
    {
        $this->httpClient->enqueue(new Response(404));

        self::assertFalse($this->subject->delete('/some/file.txt'));
    }

    // ---------------------------------------------------------------------------------------------
    // uploadFile
    // ---------------------------------------------------------------------------------------------

    public function testUploadFileSendsStreamContentsViaPut(): void
    {
        $this->httpClient->enqueue(new Response(201));

        $stream = $this->createStreamWithContents('file contents');
        self::assertTrue($this->subject->uploadFile('/folder/file.txt', $stream));

        $request = $this->httpClient->getLastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('https://dav.example.com/dav/folder/file.txt', (string)$request->getUri());
        self::assertSame('file contents', (string)$request->getBody());
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public function uploadStatusCodes(): array
    {
        return [
            'created' => [201, true],
            'no content (overwritten)' => [204, true],
            'conflict (parent missing)' => [409, false],
            'insufficient storage' => [507, false],
        ];
    }

    /**
     * @dataProvider uploadStatusCodes
     */
    public function testUploadFileReportsSuccessDependingOnStatusCode(int $statusCode, bool $expected): void
    {
        $this->httpClient->enqueue(new Response($statusCode));

        self::assertSame(
            $expected,
            $this->subject->uploadFile('/file.txt', $this->createStreamWithContents('x'))
        );
    }

    // ---------------------------------------------------------------------------------------------
    // copy / move
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public function copyAndMoveMethods(): array
    {
        return [
            'copy' => ['COPY'],
            'move' => ['MOVE'],
        ];
    }

    /**
     * @dataProvider copyAndMoveMethods
     */
    public function testCopyAndMoveSendAbsoluteDestinationHeader(string $method): void
    {
        $this->httpClient->enqueue(new Response(201));

        $result = $this->callCopyOrMove($method, '/source/file.txt', '/target/file.txt');

        self::assertTrue($result);
        $request = $this->httpClient->getLastRequest();
        self::assertSame($method, $request->getMethod());
        self::assertSame('https://dav.example.com/dav/source/file.txt', (string)$request->getUri());
        self::assertSame('https://dav.example.com/dav/target/file.txt', $request->getHeaderLine('Destination'));
        self::assertSame('infinity', $request->getHeaderLine('Depth'));
    }

    /**
     * @dataProvider copyAndMoveMethods
     */
    public function testCopyAndMoveAcceptOverwriteResponse(string $method): void
    {
        $this->httpClient->enqueue(new Response(204));

        self::assertTrue($this->callCopyOrMove($method, '/a', '/b'));
    }

    /**
     * @dataProvider copyAndMoveMethods
     */
    public function testCopyAndMoveReturnFalseOnFailure(string $method): void
    {
        $this->httpClient->enqueue(new Response(412));

        self::assertFalse($this->callCopyOrMove($method, '/a', '/b'));
    }

    /**
     * @dataProvider copyAndMoveMethods
     */
    public function testCopyAndMoveReturnFalseIfServerIsUnreachable(string $method): void
    {
        $this->httpClient->enqueue($this->createClientException());

        self::assertFalse($this->callCopyOrMove($method, '/a', '/b'));
    }

    // ---------------------------------------------------------------------------------------------
    // get
    // ---------------------------------------------------------------------------------------------

    public function testGetReturnsResponseBody(): void
    {
        $this->httpClient->enqueue(new Response(200, [], 'the contents'));

        self::assertSame('the contents', $this->subject->get('/file.txt'));
        self::assertSame(['GET https://dav.example.com/dav/file.txt'], $this->describeRequests());
    }

    public function testGetThrowsIfFileIsNotAvailable(): void
    {
        $this->httpClient->enqueue(new Response(404));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1643139962);
        $this->subject->get('/file.txt');
    }

    public function testGetThrowsIfServerIsUnreachable(): void
    {
        $this->httpClient->enqueue($this->createClientException());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1643139962);
        $this->subject->get('/file.txt');
    }

    // ---------------------------------------------------------------------------------------------
    // getProperties
    // ---------------------------------------------------------------------------------------------

    public function testGetPropertiesParsesPropfindResponse(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                [
                    'href' => '/dav/folder/image.png',
                    'props' => '<D:creationdate>2022-01-26T10:00:00Z</D:creationdate>'
                        . '<D:getcontentlength>1234</D:getcontentlength>'
                        . '<D:getlastmodified>Wed, 26 Jan 2022 12:30:00 GMT</D:getlastmodified>'
                        . '<D:getcontenttype>image/png</D:getcontenttype>',
                ],
            ])
        );

        $properties = $this->subject->getProperties('/folder/image.png');

        self::assertSame(
            [
                'ctime' => \strtotime('2022-01-26T10:00:00Z'),
                'size' => 1234,
                'mtime' => \strtotime('Wed, 26 Jan 2022 12:30:00 GMT'),
                'mimetype' => 'image/png',
            ],
            $properties
        );

        $request = $this->httpClient->getLastRequest();
        self::assertSame('PROPFIND', $request->getMethod());
        self::assertSame('https://dav.example.com/dav/folder/image.png', (string)$request->getUri());
        self::assertSame('0', $request->getHeaderLine('Depth'));
        self::assertStringContainsString('getcontentlength', (string)$request->getBody());
    }

    public function testGetPropertiesIgnoresUnparsableModificationTime(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                [
                    'href' => '/dav/file.txt',
                    'props' => '<D:getlastmodified>not a date</D:getlastmodified>'
                        . '<D:getcontentlength>5</D:getcontentlength>',
                ],
            ])
        );

        self::assertSame(['size' => 5], $this->subject->getProperties('/file.txt'));
    }

    public function testGetPropertiesFollowsRedirects(): void
    {
        $this->httpClient
            ->enqueue(new Response(301, ['Location' => 'https://dav.example.com/dav/folder/']))
            ->enqueue(
                $this->createMultiStatusResponse([
                    ['href' => '/dav/folder/', 'props' => '<D:getcontenttype>httpd/unix-directory</D:getcontenttype>'],
                ])
            );

        $properties = $this->subject->getProperties('/folder');

        self::assertSame(['mimetype' => 'httpd/unix-directory'], $properties);
        self::assertSame(
            [
                'PROPFIND https://dav.example.com/dav/folder',
                'PROPFIND https://dav.example.com/dav/folder/',
            ],
            $this->describeRequests()
        );
    }

    public function testGetPropertiesThrowsOnRedirectWithoutLocation(): void
    {
        $this->httpClient->enqueue(new Response(302));

        $this->expectException(FileOperationErrorException::class);
        $this->expectExceptionCode(1643218355);
        $this->subject->getProperties('/folder');
    }

    public function testGetPropertiesReturnsEmptyArrayIfPropstatIsNotOk(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/file.txt', 'props' => '<D:getcontentlength/>', 'status' => 'HTTP/1.1 404 Not Found'],
            ])
        );

        self::assertSame([], $this->subject->getProperties('/file.txt'));
    }

    public function testGetPropertiesReturnsEmptyArrayForMissingResource(): void
    {
        // Same result as for an unreachable server or a non-OK propstat.
        $this->httpClient->enqueue(new Response(404));

        self::assertSame([], $this->subject->getProperties('/missing.txt'));
    }

    public function testGetPropertiesReturnsEmptyArrayIfServerIsUnreachable(): void
    {
        $this->httpClient->enqueue($this->createClientException());

        self::assertSame([], $this->subject->getProperties('/file.txt'));
    }

    // ---------------------------------------------------------------------------------------------
    // getDescendantPaths
    // ---------------------------------------------------------------------------------------------

    public function testGetDescendantPathsMapsPathsToTypes(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/folder/', 'props' => '<D:getcontenttype>httpd/unix-directory</D:getcontenttype>'],
                ['href' => '/dav/folder/sub/', 'props' => '<D:getcontenttype>httpd/unix-directory</D:getcontenttype>'],
                ['href' => '/dav/folder/file.txt', 'props' => '<D:getcontenttype>text/plain</D:getcontenttype>'],
                // Apache's mod_dav does not report a content type for files of unknown type.
                ['href' => '/dav/folder/unknown', 'props' => ''],
            ])
        );

        self::assertSame(
            [
                '/folder/' => 'directory',
                '/folder/sub/' => 'directory',
                '/folder/file.txt' => 'file',
                '/folder/unknown' => 'file',
            ],
            $this->subject->getDescendantPaths('/folder/', false)
        );

        $request = $this->httpClient->getLastRequest();
        self::assertSame('PROPFIND', $request->getMethod());
        self::assertSame('1', $request->getHeaderLine('Depth'));
    }

    public function testGetDescendantPathsUsesInfiniteDepthWhenRecursive(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/folder/', 'props' => '<D:getcontenttype>httpd/unix-directory</D:getcontenttype>'],
            ])
        );

        $this->subject->getDescendantPaths('/folder/', true);

        self::assertSame('infinity', $this->httpClient->getLastRequest()->getHeaderLine('Depth'));
    }

    public function testGetDescendantPathsHandlesAbsoluteHrefs(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                [
                    'href' => 'https://dav.example.com/dav/folder/',
                    'props' => '<D:getcontenttype>httpd/unix-directory</D:getcontenttype>',
                ],
                [
                    'href' => 'https://dav.example.com/dav/folder/file.txt',
                    'props' => '<D:getcontenttype>text/plain</D:getcontenttype>',
                ],
            ])
        );

        self::assertSame(
            ['/folder/' => 'directory', '/folder/file.txt' => 'file'],
            $this->subject->getDescendantPaths('/folder/', false)
        );
    }

    public function testGetDescendantPathsDecodesPercentEncodedHrefs(): void
    {
        // Servers return hrefs URL-encoded (RFC 4918, section 8.3.1); FAL identifiers are not encoded.
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/folder/', 'props' => '<D:getcontenttype>httpd/unix-directory</D:getcontenttype>'],
                ['href' => '/dav/folder/my%20file.txt', 'props' => '<D:getcontenttype>text/plain</D:getcontenttype>'],
                ['href' => '/dav/folder/K%c3%bche.txt', 'props' => '<D:getcontenttype>text/plain</D:getcontenttype>'],
            ])
        );

        self::assertSame(
            ['/folder/' => 'directory', '/folder/my file.txt' => 'file', '/folder/Kühe.txt' => 'file'],
            $this->subject->getDescendantPaths('/folder/', false)
        );
    }

    public function testGetDescendantPathsSkipsMalformedResponseEntries(): void
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<D:multistatus xmlns:D="DAV:">'
            . $this->createResponseXml('/dav/folder/', '<D:getcontenttype>httpd/unix-directory</D:getcontenttype>')
            // No href.
            . '<D:response><D:propstat><D:prop/><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>'
            // No status.
            . '<D:response><D:href>/dav/folder/a</D:href><D:propstat><D:prop/></D:propstat></D:response>'
            // Unparsable status line.
            . '<D:response><D:href>/dav/folder/b</D:href>'
            . '<D:propstat><D:prop/><D:status>garbage</D:status></D:propstat></D:response>'
            . $this->createResponseXml('/dav/folder/c', '')
            . '</D:multistatus>';
        $this->httpClient->enqueue(new Response(207, [], $body));

        self::assertSame(
            ['/folder/' => 'directory', '/folder/c' => 'file'],
            $this->subject->getDescendantPaths('/folder/', false)
        );
    }

    public function testGetDescendantPathsReturnsNullIfServerIsUnreachable(): void
    {
        $this->httpClient->enqueue($this->createClientException());

        self::assertNull($this->subject->getDescendantPaths('/folder/', true));
    }

    public function testGetDescendantPathsReturnsNullForMissingFolder(): void
    {
        $this->httpClient->enqueue(new Response(404));

        self::assertNull($this->subject->getDescendantPaths('/missing/', false));
    }

    public function testGetDescendantPathsReturnsNullIfFolderItselfIsNotOk(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/folder/', 'props' => '', 'status' => 'HTTP/1.1 403 Forbidden'],
            ])
        );

        self::assertNull($this->subject->getDescendantPaths('/folder/', false));
    }

    // ---------------------------------------------------------------------------------------------
    // getDescendantProperties
    // ---------------------------------------------------------------------------------------------

    public function testGetDescendantPropertiesReturnsPropertiesOfAllEntries(): void
    {
        // Layout as returned by Apache's mod_dav: found and missing properties in separate propstats.
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<D:multistatus xmlns:D="DAV:"><D:response xmlns:lp1="DAV:">'
            . '<D:href>/dav/folder/</D:href>'
            . '<D:propstat><D:prop><D:getcontenttype>httpd/unix-directory</D:getcontenttype>'
            . '<lp1:getlastmodified>Wed, 26 Jan 2022 12:30:00 GMT</lp1:getlastmodified></D:prop>'
            . '<D:status>HTTP/1.1 200 OK</D:status></D:propstat>'
            . '<D:propstat><D:prop><D:getcontentlength/></D:prop>'
            . '<D:status>HTTP/1.1 404 Not Found</D:status></D:propstat>'
            . '</D:response><D:response xmlns:lp1="DAV:">'
            . '<D:href>/dav/folder/file.txt</D:href>'
            // Missing properties first, to check that the order does not matter.
            . '<D:propstat><D:prop><D:getcontenttype/></D:prop>'
            . '<D:status>HTTP/1.1 404 Not Found</D:status></D:propstat>'
            . '<D:propstat><D:prop><lp1:getcontentlength>42</lp1:getcontentlength>'
            . '<lp1:creationdate>2022-01-26T10:00:00Z</lp1:creationdate></D:prop>'
            . '<D:status>HTTP/1.1 200 OK</D:status></D:propstat>'
            . '</D:response></D:multistatus>';
        $this->httpClient->enqueue(new Response(207, [], $body));

        self::assertSame(
            [
                '/folder/' => [
                    'mimetype' => 'httpd/unix-directory',
                    'mtime' => \strtotime('Wed, 26 Jan 2022 12:30:00 GMT'),
                ],
                '/folder/file.txt' => [
                    'size' => 42,
                    'ctime' => \strtotime('2022-01-26T10:00:00Z'),
                ],
            ],
            $this->subject->getDescendantProperties('/folder/', false)
        );

        $request = $this->httpClient->getLastRequest();
        self::assertSame('PROPFIND', $request->getMethod());
        self::assertSame('1', $request->getHeaderLine('Depth'));
    }

    public function testGetDescendantPropertiesUsesInfiniteDepthWhenRecursive(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([['href' => '/dav/folder/', 'props' => '']])
        );

        $this->subject->getDescendantProperties('/folder/', true);

        self::assertSame('infinity', $this->httpClient->getLastRequest()->getHeaderLine('Depth'));
    }

    public function testGetDescendantPropertiesReturnsNullForMissingFolder(): void
    {
        $this->httpClient->enqueue(new Response(404));

        self::assertNull($this->subject->getDescendantProperties('/missing/', false));
    }

    public function testGetDescendantPropertiesReturnsNullIfServerIsUnreachable(): void
    {
        $this->httpClient->enqueue($this->createClientException());

        self::assertNull($this->subject->getDescendantProperties('/folder/', false));
    }

    // ---------------------------------------------------------------------------------------------
    // isFolderEmpty
    // ---------------------------------------------------------------------------------------------

    public function testIsFolderEmptyReturnsTrueIfOnlyTheFolderItselfIsListed(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/folder/', 'props' => '<D:resourcetype/>'],
            ])
        );

        self::assertTrue($this->subject->isFolderEmpty('/folder/'));
        $request = $this->httpClient->getLastRequest();
        self::assertSame('PROPFIND', $request->getMethod());
        self::assertSame('1', $request->getHeaderLine('Depth'));
    }

    public function testIsFolderEmptyReturnsFalseIfFolderHasChildren(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/folder/', 'props' => '<D:resourcetype/>'],
                ['href' => '/dav/folder/file.txt', 'props' => '<D:getcontentlength/>'],
            ])
        );

        self::assertFalse($this->subject->isFolderEmpty('/folder/'));
    }

    public function testIsFolderEmptyReturnsFalseIfFolderHasOnlySubFolders(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/folder/', 'props' => '<D:resourcetype/>'],
                ['href' => '/dav/folder/sub/', 'props' => '<D:resourcetype/>'],
            ])
        );

        self::assertFalse($this->subject->isFolderEmpty('/folder/'));
    }

    /**
     * Like TYPO3's LocalDriver (whose opendir() fails), a folder that cannot be read counts as empty.
     *
     * @return array<string, array{0: int}>
     */
    public function unreadableFolderStatusCodes(): array
    {
        return [
            'not found' => [404],
            'forbidden' => [403],
            'server error' => [500],
        ];
    }

    /**
     * @dataProvider unreadableFolderStatusCodes
     */
    public function testIsFolderEmptyReturnsTrueIfFolderCannotBeRead(int $statusCode): void
    {
        $this->httpClient->enqueue(new Response($statusCode));

        self::assertTrue($this->subject->isFolderEmpty('/folder/'));
    }

    public function testIsFolderEmptyReturnsTrueIfServerIsUnreachable(): void
    {
        $this->httpClient->enqueue($this->createClientException());

        self::assertTrue($this->subject->isFolderEmpty('/folder/'));
    }

    public function testIsFolderEmptyReturnsTrueIfMultiStatusResponseHasNoEntries(): void
    {
        $this->httpClient->enqueue($this->createMultiStatusResponse([]));

        self::assertTrue($this->subject->isFolderEmpty('/folder/'));
    }

    public function testIsFolderEmptyReturnsTrueIfFolderItselfIsNotOk(): void
    {
        $this->httpClient->enqueue(
            $this->createMultiStatusResponse([
                ['href' => '/dav/folder/', 'props' => '', 'status' => 'HTTP/1.1 403 Forbidden'],
            ])
        );

        self::assertTrue($this->subject->isFolderEmpty('/folder/'));
    }

    // ---------------------------------------------------------------------------------------------
    // getPublicUrl
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function publicUrlPaths(): array
    {
        return [
            'leading slash' => ['/folder/file.txt', 'https://files.example.com/file/folder/file.txt'],
            'no leading slash' => ['folder/file.txt', 'https://files.example.com/file/folder/file.txt'],
            'root file' => ['/file.txt', 'https://files.example.com/file/file.txt'],
        ];
    }

    /**
     * @dataProvider publicUrlPaths
     */
    public function testGetPublicUrlPrefixesPathWithPublicUrl(string $path, string $expectedUrl): void
    {
        self::assertSame($expectedUrl, $this->subject->getPublicUrl($path));
        self::assertSame([], $this->httpClient->getRequests(), 'Building a public URL must not hit the server.');
    }

    // ---------------------------------------------------------------------------------------------
    // Error handling of the HTTP layer
    // ---------------------------------------------------------------------------------------------

    public function testInvalidRequestsAreRethrown(): void
    {
        $exception = new class ('invalid', new Request('GET', '/')) extends \RuntimeException implements RequestExceptionInterface {
            private RequestInterface $request;

            public function __construct(string $message, RequestInterface $request)
            {
                parent::__construct($message);
                $this->request = $request;
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
        $this->httpClient->enqueue($exception);

        $this->expectExceptionObject($exception);
        $this->subject->pathExists('/file.txt');
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    private function callCopyOrMove(string $method, string $source, string $target): bool
    {
        return 'COPY' === $method
            ? $this->subject->copy($source, $target)
            : $this->subject->move($source, $target);
    }

    /**
     * @return array<string>
     */
    private function describeRequests(): array
    {
        return \array_map(
            static fn (RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri(),
            $this->httpClient->getRequests()
        );
    }

    /**
     * @return resource
     */
    private function createStreamWithContents(string $contents)
    {
        $stream = \fopen('php://memory', 'rw');
        \fwrite($stream, $contents);
        \rewind($stream);
        return $stream;
    }

    private function createClientException(): ClientExceptionInterface
    {
        return new class ('Connection refused') extends \RuntimeException implements ClientExceptionInterface {
        };
    }

    /**
     * @param array<array{href: string, props: string, status?: string}> $entries
     */
    private function createMultiStatusResponse(array $entries): Response
    {
        $body = '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:">';
        foreach ($entries as $entry) {
            $body .= $this->createResponseXml($entry['href'], $entry['props'], $entry['status'] ?? 'HTTP/1.1 200 OK');
        }
        $body .= '</D:multistatus>';

        return new Response(207, ['Content-Type' => 'application/xml; charset="utf-8"'], $body);
    }

    private function createResponseXml(string $href, string $props, string $status = 'HTTP/1.1 200 OK'): string
    {
        return '<D:response>'
            . '<D:href>' . \htmlspecialchars($href) . '</D:href>'
            . '<D:propstat><D:prop>' . $props . '</D:prop><D:status>' . $status . '</D:status></D:propstat>'
            . '</D:response>';
    }
}
