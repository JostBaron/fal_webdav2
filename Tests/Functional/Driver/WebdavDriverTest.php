<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Tests\Functional\Driver;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Jbaron\FalWebdav\Driver\WebdavClientFactory;
use Jbaron\FalWebdav\Driver\WebdavDriver;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Http\UriFactory;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Runs the driver against a real Apache mod_dav server configured like the sites' "processed-files"
 * container. Start it with:
 *
 *     docker compose -f Tests/Functional/Fixtures/webdav-server/docker-compose.yaml up -d --build
 *
 * Every test works inside its own, freshly created folder, which is removed afterwards.
 *
 * @coversNothing
 */
class WebdavDriverTest extends TestCase
{
    private Client $httpClient;
    private string $serverUrl;
    private WebdavDriver $subject;

    /**
     * Folder (FAL identifier) the current test works in, e.g. "/test-1a2b3c4d/".
     */
    private string $testFolder;

    /**
     * @var array<string>
     */
    private array $localFilesToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverUrl = \rtrim((string)\getenv('FAL_WEBDAV_TEST_SERVER'), '/');
        $this->httpClient = new Client(['timeout' => 10]);
        $this->skipIfServerIsUnavailable();

        $this->subject = $this->createDriver();
        $this->testFolder = $this->subject->createFolder('test-' . \bin2hex(\random_bytes(4)));
    }

    protected function tearDown(): void
    {
        if (isset($this->subject, $this->testFolder)) {
            $this->subject->deleteFolder($this->testFolder, true);
        }
        foreach ($this->localFilesToRemove as $file) {
            @\unlink($file);
        }
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Folders
    // ---------------------------------------------------------------------------------------------

    public function testRootFolderExists(): void
    {
        self::assertTrue($this->subject->folderExists('/'));
    }

    public function testCreateFolderCreatesMissingParentFolders(): void
    {
        $identifier = $this->subject->createFolder('a/b/c', $this->testFolder);

        self::assertSame($this->testFolder . 'a/b/c/', $identifier);
        self::assertTrue($this->subject->folderExists($this->testFolder . 'a/'));
        self::assertTrue($this->subject->folderExists($this->testFolder . 'a/b/'));
        self::assertTrue($this->subject->folderExists($identifier));
        self::assertTrue($this->subject->folderExistsInFolder('c', $this->testFolder . 'a/b/'));
    }

    public function testCreatingAnExistingFolderSucceeds(): void
    {
        $this->subject->createFolder('existing', $this->testFolder);

        self::assertSame(
            $this->testFolder . 'existing/',
            $this->subject->createFolder('existing', $this->testFolder)
        );
    }

    public function testFolderExistsReturnsFalseForMissingFolder(): void
    {
        self::assertFalse($this->subject->folderExists($this->testFolder . 'missing/'));
    }

    public function testIsFolderEmpty(): void
    {
        $folder = $this->subject->createFolder('folder', $this->testFolder);
        self::assertTrue($this->subject->isFolderEmpty($folder));

        $this->subject->setFileContents($folder . 'file.txt', 'x');
        self::assertFalse($this->subject->isFolderEmpty($folder));
    }

    public function testFolderWithOnlySubFoldersIsNotEmpty(): void
    {
        $folder = $this->subject->createFolder('folder/sub', $this->testFolder);

        self::assertFalse($this->subject->isFolderEmpty($this->testFolder . 'folder/'));
        self::assertTrue($this->subject->isFolderEmpty($folder));
    }

    public function testMissingFolderIsEmpty(): void
    {
        // Like TYPO3's LocalDriver.
        self::assertTrue($this->subject->isFolderEmpty($this->testFolder . 'missing/'));
    }

    public function testDeletingMissingFolderFailsWithoutException(): void
    {
        self::assertFalse($this->subject->deleteFolder($this->testFolder . 'missing/'));
    }

    public function testGetFoldersInFolder(): void
    {
        $this->subject->createFolder('b', $this->testFolder);
        $this->subject->createFolder('a/nested', $this->testFolder);
        $this->subject->setFileContents($this->testFolder . 'file.txt', 'x');

        self::assertSame(
            [$this->testFolder . 'a/', $this->testFolder . 'b/'],
            $this->subject->getFoldersInFolder($this->testFolder)
        );
        self::assertSame(
            [$this->testFolder . 'a/', $this->testFolder . 'a/nested/', $this->testFolder . 'b/'],
            $this->subject->getFoldersInFolder($this->testFolder, 0, 0, true)
        );
        self::assertSame(2, $this->subject->countFoldersInFolder($this->testFolder));
    }

    public function testRenameFolderMovesContentsAndReturnsIdentifierMap(): void
    {
        $this->subject->createFolder('old/sub', $this->testFolder);
        $this->subject->setFileContents($this->testFolder . 'old/sub/file.txt', 'contents');

        $map = $this->subject->renameFolder($this->testFolder . 'old/', 'new');

        self::assertSame(
            [
                $this->testFolder . 'old/' => $this->testFolder . 'new/',
                $this->testFolder . 'old/sub/' => $this->testFolder . 'new/sub/',
                $this->testFolder . 'old/sub/file.txt' => $this->testFolder . 'new/sub/file.txt',
            ],
            $this->sortByKey($map)
        );
        self::assertFalse($this->subject->folderExists($this->testFolder . 'old/'));
        self::assertSame('contents', $this->subject->getFileContents($this->testFolder . 'new/sub/file.txt'));
    }

    public function testMoveFolderWithinStorage(): void
    {
        $this->subject->createFolder('source', $this->testFolder);
        $this->subject->createFolder('target', $this->testFolder);
        $this->subject->setFileContents($this->testFolder . 'source/file.txt', 'contents');

        $map = $this->subject->moveFolderWithinStorage(
            $this->testFolder . 'source/',
            $this->testFolder . 'target/',
            'moved'
        );

        self::assertSame(
            [
                $this->testFolder . 'source/' => $this->testFolder . 'target/moved/',
                $this->testFolder . 'source/file.txt' => $this->testFolder . 'target/moved/file.txt',
            ],
            $this->sortByKey($map)
        );
        self::assertFalse($this->subject->folderExists($this->testFolder . 'source/'));
        self::assertSame('contents', $this->subject->getFileContents($this->testFolder . 'target/moved/file.txt'));
    }

    public function testCopyFolderWithinStorage(): void
    {
        $this->subject->createFolder('source/sub', $this->testFolder);
        $this->subject->setFileContents($this->testFolder . 'source/sub/file.txt', 'contents');

        self::assertTrue(
            $this->subject->copyFolderWithinStorage($this->testFolder . 'source/', $this->testFolder, 'copy')
        );

        self::assertTrue($this->subject->folderExists($this->testFolder . 'source/'));
        self::assertSame('contents', $this->subject->getFileContents($this->testFolder . 'copy/sub/file.txt'));
    }

    public function testDeleteFolderRefusesNonEmptyFolderUnlessRecursive(): void
    {
        $folder = $this->subject->createFolder('folder', $this->testFolder);
        $this->subject->setFileContents($folder . 'file.txt', 'x');

        self::assertFalse($this->subject->deleteFolder($folder));
        self::assertTrue($this->subject->folderExists($folder));

        self::assertTrue($this->subject->deleteFolder($folder, true));
        self::assertFalse($this->subject->folderExists($folder));
    }

    public function testDeleteEmptyFolder(): void
    {
        $folder = $this->subject->createFolder('folder', $this->testFolder);

        self::assertTrue($this->subject->deleteFolder($folder));
        self::assertFalse($this->subject->folderExists($folder));
    }

    // ---------------------------------------------------------------------------------------------
    // Files
    // ---------------------------------------------------------------------------------------------

    public function testAddFileUploadsAndRemovesLocalFile(): void
    {
        $localFile = $this->createLocalFile('uploaded contents');

        $identifier = $this->subject->addFile($localFile, $this->testFolder, 'uploaded.txt');

        self::assertSame($this->testFolder . 'uploaded.txt', $identifier);
        self::assertFileDoesNotExist($localFile);
        self::assertTrue($this->subject->fileExists($identifier));
        self::assertTrue($this->subject->fileExistsInFolder('uploaded.txt', $this->testFolder));
        self::assertSame('uploaded contents', $this->subject->getFileContents($identifier));
    }

    public function testAddFileOverwritesExistingFile(): void
    {
        $this->subject->setFileContents($this->testFolder . 'file.txt', 'old');

        $this->subject->addFile($this->createLocalFile('new'), $this->testFolder, 'file.txt');

        self::assertSame('new', $this->subject->getFileContents($this->testFolder . 'file.txt'));
    }

    public function testCreateFileCreatesEmptyFile(): void
    {
        self::assertTrue($this->subject->createFile('empty.txt', $this->testFolder));

        self::assertTrue($this->subject->fileExists($this->testFolder . 'empty.txt'));
        self::assertSame('', $this->subject->getFileContents($this->testFolder . 'empty.txt'));
    }

    public function testFileExistsReturnsFalseForMissingFile(): void
    {
        self::assertFalse($this->subject->fileExists($this->testFolder . 'missing.txt'));
    }

    public function testSetAndGetFileContents(): void
    {
        $contents = \random_bytes(100000);

        self::assertSame(100000, $this->subject->setFileContents($this->testFolder . 'binary.bin', $contents));
        self::assertSame($contents, $this->subject->getFileContents($this->testFolder . 'binary.bin'));
    }

    public function testReplaceFile(): void
    {
        $this->subject->setFileContents($this->testFolder . 'file.txt', 'old');

        self::assertTrue($this->subject->replaceFile($this->testFolder . 'file.txt', $this->createLocalFile('new')));
        self::assertSame('new', $this->subject->getFileContents($this->testFolder . 'file.txt'));
    }

    public function testCopyFileWithinStorage(): void
    {
        $this->subject->setFileContents($this->testFolder . 'file.txt', 'contents');
        $target = $this->subject->createFolder('target', $this->testFolder);

        $identifier = $this->subject->copyFileWithinStorage($this->testFolder . 'file.txt', $target, 'copy.txt');

        self::assertSame($target . 'copy.txt', $identifier);
        self::assertSame('contents', $this->subject->getFileContents($identifier));
        self::assertTrue($this->subject->fileExists($this->testFolder . 'file.txt'));
    }

    public function testMoveFileWithinStorage(): void
    {
        $this->subject->setFileContents($this->testFolder . 'file.txt', 'contents');
        $target = $this->subject->createFolder('target', $this->testFolder);

        $identifier = $this->subject->moveFileWithinStorage($this->testFolder . 'file.txt', $target, 'moved.txt');

        self::assertSame($target . 'moved.txt', $identifier);
        self::assertSame('contents', $this->subject->getFileContents($identifier));
        self::assertFalse($this->subject->fileExists($this->testFolder . 'file.txt'));
    }

    public function testRenameFile(): void
    {
        $this->subject->setFileContents($this->testFolder . 'file.txt', 'contents');

        $identifier = $this->subject->renameFile($this->testFolder . 'file.txt', 'renamed.txt');

        self::assertSame($this->testFolder . 'renamed.txt', $identifier);
        self::assertSame('contents', $this->subject->getFileContents($identifier));
        self::assertFalse($this->subject->fileExists($this->testFolder . 'file.txt'));
    }

    public function testDeleteFile(): void
    {
        $this->subject->setFileContents($this->testFolder . 'file.txt', 'contents');

        self::assertTrue($this->subject->deleteFile($this->testFolder . 'file.txt'));
        self::assertFalse($this->subject->fileExists($this->testFolder . 'file.txt'));
    }

    public function testGetFilesInFolder(): void
    {
        $this->subject->setFileContents($this->testFolder . 'b.txt', 'b');
        $this->subject->setFileContents($this->testFolder . 'a.txt', 'a');
        $this->subject->createFolder('sub', $this->testFolder);
        $this->subject->setFileContents($this->testFolder . 'sub/c.txt', 'c');

        self::assertSame(
            [$this->testFolder . 'a.txt', $this->testFolder . 'b.txt'],
            $this->subject->getFilesInFolder($this->testFolder)
        );
        self::assertSame(
            [$this->testFolder . 'a.txt', $this->testFolder . 'b.txt', $this->testFolder . 'sub/c.txt'],
            $this->subject->getFilesInFolder($this->testFolder, 0, 0, true)
        );
        self::assertSame(2, $this->subject->countFilesInFolder($this->testFolder));
    }

    public function testFilesCanBeSortedBySize(): void
    {
        $this->subject->setFileContents($this->testFolder . 'a.txt', \str_repeat('x', 30));
        $this->subject->setFileContents($this->testFolder . 'b.txt', \str_repeat('x', 200));
        $this->subject->setFileContents($this->testFolder . 'c.txt', 'x');

        self::assertSame(
            [$this->testFolder . 'c.txt', $this->testFolder . 'a.txt', $this->testFolder . 'b.txt'],
            $this->subject->getFilesInFolder($this->testFolder, 0, 0, false, [], 'size')
        );
        self::assertSame(
            [$this->testFolder . 'b.txt', $this->testFolder . 'a.txt', $this->testFolder . 'c.txt'],
            $this->subject->getFilesInFolder($this->testFolder, 0, 0, false, [], 'size', true)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function timeSortFields(): array
    {
        return [
            'modification time' => ['tstamp'],
            'creation time' => ['crdate'],
        ];
    }

    /**
     * WebDAV reports times with a resolution of one second, hence the sleeps.
     *
     * @dataProvider timeSortFields
     */
    public function testFilesCanBeSortedByTime(string $sort): void
    {
        $this->subject->setFileContents($this->testFolder . 'b.txt', 'first');
        \sleep(1);
        $this->subject->setFileContents($this->testFolder . 'c.txt', 'second');
        \sleep(1);
        $this->subject->setFileContents($this->testFolder . 'a.txt', 'third');

        self::assertSame(
            [$this->testFolder . 'b.txt', $this->testFolder . 'c.txt', $this->testFolder . 'a.txt'],
            $this->subject->getFilesInFolder($this->testFolder, 0, 0, false, [], $sort)
        );
        self::assertSame(
            [$this->testFolder . 'a.txt', $this->testFolder . 'c.txt', $this->testFolder . 'b.txt'],
            $this->subject->getFilesInFolder($this->testFolder, 0, 0, false, [], $sort, true)
        );
    }

    public function testFileInformationIsReadFromServer(): void
    {
        $before = \time();
        $this->subject->setFileContents($this->testFolder . 'image.png', \str_repeat('x', 1234));

        $info = $this->subject->getFileInfoByIdentifier($this->testFolder . 'image.png');

        self::assertSame(1234, $info['size']);
        self::assertSame('image/png', $info['mimetype']);
        self::assertSame('image.png', $info['name']);
        self::assertSame('png', $info['extension']);
        self::assertSame($this->testFolder . 'image.png', $info['identifier']);
        self::assertEqualsWithDelta($before, $info['mtime'], 60);
        self::assertEqualsWithDelta($before, $info['ctime'], 60);
    }

    public function testHash(): void
    {
        $this->subject->setFileContents($this->testFolder . 'file.txt', 'contents');

        self::assertSame(\sha1('contents'), $this->subject->hash($this->testFolder . 'file.txt', 'sha1'));
    }

    public function testGetFileForLocalProcessing(): void
    {
        $this->subject->setFileContents($this->testFolder . 'image.jpg', 'jpeg data');

        $localPath = $this->subject->getFileForLocalProcessing($this->testFolder . 'image.jpg');

        self::assertStringEndsWith('.jpg', $localPath);
        self::assertSame('jpeg data', \file_get_contents($localPath));
    }

    public function testPublicUrlServesFile(): void
    {
        $this->subject->setFileContents($this->testFolder . 'public.txt', 'public contents');

        $publicUrl = $this->subject->getPublicUrl($this->testFolder . 'public.txt');

        self::assertSame($this->serverUrl . '/file' . $this->testFolder . 'public.txt', $publicUrl);
        $response = $this->httpClient->request('GET', $publicUrl, ['http_errors' => false]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public contents', (string)$response->getBody());
    }

    // ---------------------------------------------------------------------------------------------
    // Missing resources and failing operations
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public function listingMethods(): array
    {
        return [
            'files' => ['getFilesInFolder'],
            'folders' => ['getFoldersInFolder'],
        ];
    }

    /**
     * Like TYPO3's LocalDriver, listing a folder that does not exist is an error, and the error
     * names the folder.
     *
     * @dataProvider listingMethods
     */
    public function testListingMissingFolderThrows(string $method): void
    {
        $missingFolder = $this->testFolder . 'missing/';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($missingFolder);
        $this->subject->{$method}($missingFolder);
    }

    /**
     * @return array<string, array{0: string, 1: array<string>}>
     */
    public function operationsOnMissingFile(): array
    {
        return [
            'copy' => ['copyFileWithinStorage', ['missing.txt', '', 'copy.txt']],
            'move' => ['moveFileWithinStorage', ['missing.txt', '', 'moved.txt']],
            'rename' => ['renameFile', ['missing.txt', 'renamed.txt']],
        ];
    }

    /**
     * @dataProvider operationsOnMissingFile
     * @param array<string> $arguments
     */
    public function testOperationsOnMissingFileThrow(string $method, array $arguments): void
    {
        // Prefix the identifiers with the test folder (the data provider runs before setUp()).
        $arguments[0] = $this->testFolder . $arguments[0];
        if ('renameFile' !== $method) {
            $arguments[1] = $this->testFolder . $arguments[1];
        }

        $this->expectException(FileOperationErrorException::class);
        $this->subject->{$method}(...$arguments);
    }

    public function testDriverWorksWithOtherPsr7Implementations(): void
    {
        // Guzzle's PSR-7 streams close the wrapped resource when they are destroyed. The driver
        // must not rely on the stream resource still being open after the upload.
        $httpFactory = new HttpFactory();
        GeneralUtility::addInstance(
            WebdavClientFactory::class,
            new WebdavClientFactory($httpFactory, $httpFactory, $httpFactory, $this->httpClient)
        );
        $driver = new WebdavDriver([
            'webdav_url' => $this->serverUrl . '/dav/',
            'public_url' => $this->serverUrl . '/file/',
        ]);
        $driver->processConfiguration();
        $driver->initialize();

        self::assertSame(8, $driver->setFileContents($this->testFolder . 'file.txt', 'contents'));
        self::assertTrue($driver->createFile('empty.txt', $this->testFolder));
        self::assertSame(
            $this->testFolder . 'added.txt',
            $driver->addFile($this->createLocalFile('added'), $this->testFolder, 'added.txt')
        );
        self::assertSame('contents', $driver->getFileContents($this->testFolder . 'file.txt'));
    }

    // ---------------------------------------------------------------------------------------------
    // Special characters in names
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public function specialFileNames(): array
    {
        return [
            'space' => ['my file.txt'],
            'umlaut' => ['Kühe.txt'],
            'plus' => ['a+b.txt'],
        ];
    }

    /**
     * @dataProvider specialFileNames
     */
    public function testFilesWithSpecialCharactersCanBeStoredAndListed(string $fileName): void
    {
        $identifier = $this->testFolder . $fileName;

        $this->subject->setFileContents($identifier, 'contents');

        self::assertTrue($this->subject->fileExists($identifier));
        self::assertSame('contents', $this->subject->getFileContents($identifier));
        self::assertSame([$identifier], $this->subject->getFilesInFolder($this->testFolder));
    }

    /**
     * @dataProvider specialFileNames
     */
    public function testFilesWithSpecialCharactersCanBeRenamed(string $fileName): void
    {
        $this->subject->setFileContents($this->testFolder . 'plain.txt', 'contents');

        $identifier = $this->subject->renameFile($this->testFolder . 'plain.txt', $fileName);

        self::assertSame('contents', $this->subject->getFileContents($identifier));
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    private function skipIfServerIsUnavailable(): void
    {
        try {
            $response = $this->httpClient->request(
                'PROPFIND',
                $this->serverUrl . '/dav/',
                ['headers' => ['Depth' => '0'], 'http_errors' => false, 'timeout' => 2]
            );
            $available = 207 === $response->getStatusCode();
        } catch (\Throwable $e) {
            $available = false;
        }

        if (!$available) {
            self::markTestSkipped(\sprintf(
                'No WebDAV test server at %s. Start it with "docker compose -f '
                . 'Tests/Functional/Fixtures/webdav-server/docker-compose.yaml up -d --build" '
                . 'or point FAL_WEBDAV_TEST_SERVER to another server.',
                $this->serverUrl
            ));
        }
    }

    private function createDriver(): WebdavDriver
    {
        // Same PSR-17/PSR-18 implementations that TYPO3's DI container injects in production
        // (see EXT:core/Configuration/Services.yaml).
        GeneralUtility::addInstance(
            WebdavClientFactory::class,
            new WebdavClientFactory(new RequestFactory(), new StreamFactory(), new UriFactory(), $this->httpClient)
        );

        $driver = new WebdavDriver([
            'webdav_url' => $this->serverUrl . '/dav/',
            'public_url' => $this->serverUrl . '/file/',
        ]);
        $driver->setStorageUid(1);
        $driver->processConfiguration();
        $driver->initialize();

        return $driver;
    }

    private function createLocalFile(string $contents): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'fal_webdav2_test');
        \file_put_contents($path, $contents);
        $this->localFilesToRemove[] = $path;
        return $path;
    }

    /**
     * @param array<string, string> $array
     * @return array<string, string>
     */
    private function sortByKey(array $array): array
    {
        \ksort($array);
        return $array;
    }
}
