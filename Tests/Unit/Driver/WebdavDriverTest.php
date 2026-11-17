<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Tests\Unit\Driver;

use Jbaron\FalWebdav\Driver\WebdavClient;
use Jbaron\FalWebdav\Driver\WebdavClientFactory;
use Jbaron\FalWebdav\Driver\WebdavDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\ResourceStorageInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @covers \Jbaron\FalWebdav\Driver\WebdavDriver
 */
class WebdavDriverTest extends TestCase
{
    private const STORAGE_UID = 42;

    /**
     * @var WebdavClient&MockObject
     */
    private $webdavClient;

    private WebdavDriver $subject;

    /**
     * @var array<string>
     */
    private array $localFilesToRemove = [];

    /**
     * @var array<string>
     */
    private array $localDirectoriesToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->webdavClient = $this->createMock(WebdavClient::class);
        $this->subject = $this->createDriver(
            $this->webdavClient,
            ['webdav_url' => 'https://dav.example.com/dav/', 'public_url' => 'https://files.example.com/']
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->localFilesToRemove as $file) {
            if (\file_exists($file)) {
                @\chmod($file, 0600);
                @\unlink($file);
            }
        }
        foreach ($this->localDirectoriesToRemove as $directory) {
            @\rmdir($directory);
        }
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Configuration and basic properties
    // ---------------------------------------------------------------------------------------------

    public function testProcessConfigurationPassesFlexFormValuesToClientFactory(): void
    {
        $factory = $this->createMock(WebdavClientFactory::class);
        $factory->expects(self::once())
            ->method('createWebdavClient')
            ->with('https://dav.example.com/dav/', 'https://files.example.com/')
            ->willReturn($this->webdavClient);
        GeneralUtility::addInstance(WebdavClientFactory::class, $factory);

        $driver = new WebdavDriver([
            'webdav_url' => 'https://dav.example.com/dav/',
            'public_url' => 'https://files.example.com/',
        ]);
        $driver->processConfiguration();
    }

    public function testDriverIsBrowsablePublicAndWritable(): void
    {
        self::assertSame(
            ResourceStorageInterface::CAPABILITY_BROWSABLE
            | ResourceStorageInterface::CAPABILITY_PUBLIC
            | ResourceStorageInterface::CAPABILITY_WRITABLE,
            $this->subject->getCapabilities()
        );
    }

    public function testMergeConfigurationCapabilitiesRestrictsCapabilities(): void
    {
        $merged = $this->subject->mergeConfigurationCapabilities(ResourceStorageInterface::CAPABILITY_BROWSABLE);

        self::assertSame(ResourceStorageInterface::CAPABILITY_BROWSABLE, $merged);
        self::assertSame(ResourceStorageInterface::CAPABILITY_BROWSABLE, $this->subject->getCapabilities());
    }

    public function testFileSystemIsCaseSensitive(): void
    {
        self::assertTrue($this->subject->isCaseSensitiveFileSystem());
    }

    public function testRootAndDefaultFolder(): void
    {
        self::assertSame('/', $this->subject->getRootLevelFolder());
        self::assertSame('/user_upload/', $this->subject->getDefaultFolder());
    }

    public function testPermissionsAreAlwaysReadWrite(): void
    {
        self::assertSame(['r' => true, 'w' => true], $this->subject->getPermissions('/any/file.txt'));
    }

    public function testGetPublicUrlDelegatesCanonicalizedIdentifierToClient(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('getPublicUrl')
            ->with('/folder/file.txt')
            ->willReturn('https://files.example.com/folder/file.txt');

        self::assertSame(
            'https://files.example.com/folder/file.txt',
            $this->subject->getPublicUrl('folder/./file.txt')
        );
    }

    public function testIsWithin(): void
    {
        self::assertTrue($this->subject->isWithin('/folder/', '/folder/sub/file.txt'));
        self::assertTrue($this->subject->isWithin('/folder', '/folder/file.txt'));
        self::assertFalse($this->subject->isWithin('/folder/', '/folder2/file.txt'));
        self::assertFalse($this->subject->isWithin('/folder/', '/other/file.txt'));
    }

    public function testIsWithinDoesNotUseDeprecatedApi(): void
    {
        // GeneralUtility::isFirstPartOfStr() is deprecated in TYPO3 11 and removed in TYPO3 12.
        $deprecations = [];
        \set_error_handler(
            static function (int $errno, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;
                return true;
            },
            \E_DEPRECATED | \E_USER_DEPRECATED
        );
        try {
            $this->subject->isWithin('/folder/', '/folder/file.txt');
        } finally {
            \restore_error_handler();
        }

        self::assertSame([], $deprecations);
    }

    // ---------------------------------------------------------------------------------------------
    // Existence checks
    // ---------------------------------------------------------------------------------------------

    public function testRootFolderAlwaysExistsWithoutAskingTheServer(): void
    {
        $this->webdavClient->expects(self::never())->method('pathExists');

        self::assertTrue($this->subject->folderExists('/'));
    }

    public function testRootFolderIsNeverAFile(): void
    {
        $this->webdavClient->expects(self::never())->method('pathExists');

        self::assertFalse($this->subject->fileExists('/'));
    }

    public function testFileExistsDelegatesToClient(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('pathExists')
            ->with('/folder/file.txt')
            ->willReturn(true);

        self::assertTrue($this->subject->fileExists('folder/file.txt'));
    }

    public function testFolderExistsUsesCanonicalFolderIdentifier(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('pathExists')
            ->with('/folder/')
            ->willReturn(false);

        self::assertFalse($this->subject->folderExists('/folder'));
    }

    public function testFileExistsInFolder(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('pathExists')
            ->with('/folder/file.txt')
            ->willReturn(true);

        self::assertTrue($this->subject->fileExistsInFolder('file.txt', '/folder/'));
    }

    public function testFolderExistsInFolder(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('pathExists')
            ->with('/folder/sub/')
            ->willReturn(true);

        self::assertTrue($this->subject->folderExistsInFolder('sub', '/folder/'));
    }

    public function testGetFileInFolderAndGetFolderInFolderBuildIdentifiers(): void
    {
        self::assertSame('/folder/file.txt', $this->subject->getFileInFolder('file.txt', '/folder'));
        self::assertSame('/folder/sub/', $this->subject->getFolderInFolder('sub', '/folder/'));
    }

    // ---------------------------------------------------------------------------------------------
    // Folder operations
    // ---------------------------------------------------------------------------------------------

    public function testCreateFolderReturnsNewFolderIdentifier(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('createFolder')
            ->with('/parent/new/')
            ->willReturn('/parent/new/');

        self::assertSame('/parent/new/', $this->subject->createFolder('new', '/parent/'));
    }

    public function testCreateFolderInRootFolderByDefault(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('createFolder')
            ->with('/new/')
            ->willReturn('/new/');

        self::assertSame('/new/', $this->subject->createFolder('new'));
    }

    public function testCreateFolderThrowsOnFailure(): void
    {
        $this->webdavClient->method('createFolder')->willReturn(null);

        $this->expectException(FileOperationErrorException::class);
        $this->expectExceptionCode(1669558067);
        $this->subject->createFolder('new', '/parent/');
    }

    public function testRenameFolderReturnsMapOfOldToNewIdentifiers(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('move')
            ->with('/parent/old/', '/parent/new/')
            ->willReturn(true);
        $this->webdavClient->expects(self::once())
            ->method('getDescendantPaths')
            ->with('/parent/new/', true)
            ->willReturn([
                '/parent/new/' => 'directory',
                '/parent/new/sub/' => 'directory',
                '/parent/new/sub/file.txt' => 'file',
            ]);

        self::assertSame(
            [
                '/parent/old/' => '/parent/new/',
                '/parent/old/sub/' => '/parent/new/sub/',
                '/parent/old/sub/file.txt' => '/parent/new/sub/file.txt',
            ],
            $this->subject->renameFolder('/parent/old/', 'new')
        );
    }

    public function testRenameFolderThrowsIfMoveFails(): void
    {
        $this->webdavClient->method('move')->willReturn(false);
        $this->webdavClient->expects(self::never())->method('getDescendantPaths');

        $this->expectException(FileOperationErrorException::class);
        $this->expectExceptionCode(1643145995);
        $this->subject->renameFolder('/parent/old/', 'new');
    }

    public function testMoveFolderWithinStorageReturnsMapOfOldToNewIdentifiers(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('move')
            ->with('/a/source/', '/b/target/')
            ->willReturn(true);
        $this->webdavClient->method('getDescendantPaths')
            ->with('/b/target/', true)
            ->willReturn([
                '/b/target/' => 'directory',
                '/b/target/file.txt' => 'file',
            ]);

        self::assertSame(
            [
                '/a/source/' => '/b/target/',
                '/a/source/file.txt' => '/b/target/file.txt',
            ],
            $this->subject->moveFolderWithinStorage('/a/source', '/b', 'target')
        );
    }

    public function testMoveFolderWithinStorageThrowsIfMoveFails(): void
    {
        $this->webdavClient->method('move')->willReturn(false);

        $this->expectException(FileOperationErrorException::class);
        $this->subject->moveFolderWithinStorage('/a/source/', '/b/', 'target');
    }

    public function testCopyFolderWithinStorage(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('copy')
            ->with('/a/source/', '/b/target/')
            ->willReturn(true);

        self::assertTrue($this->subject->copyFolderWithinStorage('/a/source', '/b/', 'target'));
    }

    public function testDeleteFolderRecursivelyDeletesWithoutCheckingContents(): void
    {
        $this->webdavClient->expects(self::never())->method('isFolderEmpty');
        $this->webdavClient->expects(self::once())->method('delete')->with('/folder/')->willReturn(true);

        self::assertTrue($this->subject->deleteFolder('/folder', true));
    }

    public function testDeleteFolderDeletesEmptyFolder(): void
    {
        $this->webdavClient->method('isFolderEmpty')->with('/folder/')->willReturn(true);
        $this->webdavClient->expects(self::once())->method('delete')->with('/folder/')->willReturn(true);

        self::assertTrue($this->subject->deleteFolder('/folder/'));
    }

    public function testDeleteFolderRefusesToDeleteNonEmptyFolderNonRecursively(): void
    {
        $this->webdavClient->method('isFolderEmpty')->willReturn(false);
        $this->webdavClient->expects(self::never())->method('delete');

        self::assertFalse($this->subject->deleteFolder('/folder/'));
    }

    // ---------------------------------------------------------------------------------------------
    // Listings
    // ---------------------------------------------------------------------------------------------

    public function testGetFilesInFolderReturnsOnlyFilesSorted(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('getDescendantPaths')
            ->with('/folder/', false)
            ->willReturn($this->createListing());

        self::assertSame(
            ['/folder/a.txt', '/folder/b.txt'],
            $this->subject->getFilesInFolder('/folder')
        );
    }

    public function testGetFilesInFolderSortsInReverse(): void
    {
        $this->webdavClient->method('getDescendantPaths')->willReturn($this->createListing());

        self::assertSame(
            ['/folder/b.txt', '/folder/a.txt'],
            $this->subject->getFilesInFolder('/folder/', 0, 0, false, [], '', true)
        );
    }

    public function testGetFoldersInFolderSortsInReverse(): void
    {
        $this->webdavClient->method('getDescendantPaths')->willReturn($this->createListing());

        self::assertSame(
            ['/folder/sub2/', '/folder/sub1/'],
            $this->subject->getFoldersInFolder('/folder/', 0, 0, false, [], '', true)
        );
    }

    public function testGetFilesInFolderPassesRecursiveFlag(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('getDescendantPaths')
            ->with('/folder/', true)
            ->willReturn($this->createListing() + ['/folder/sub/c.txt' => 'file']);

        self::assertSame(
            ['/folder/a.txt', '/folder/b.txt', '/folder/sub/c.txt'],
            $this->subject->getFilesInFolder('/folder/', 0, 0, true)
        );
    }

    public function testGetFilesInFolderAppliesFilterCallbacks(): void
    {
        $this->webdavClient->method('getDescendantPaths')->willReturn($this->createListing());

        $receivedArguments = [];
        $filter = static function (
            string $itemName,
            string $itemIdentifier,
            string $parentIdentifier,
            array $additionalInformation,
            WebdavDriver $driver
        ) use (&$receivedArguments) {
            $receivedArguments[] = [$itemName, $itemIdentifier, $parentIdentifier];
            return 'a.txt' === $itemName ? -1 : true;
        };

        self::assertSame(['/folder/b.txt'], $this->subject->getFilesInFolder('/folder/', 0, 0, false, [$filter]));
        self::assertContains(['a.txt', '/folder/a.txt', '/folder/'], $receivedArguments);
    }

    public function testGetFoldersInFolderReturnsOnlySubFolders(): void
    {
        $this->webdavClient->method('getDescendantPaths')->with('/folder/', false)->willReturn($this->createListing());

        self::assertSame(
            ['/folder/sub1/', '/folder/sub2/'],
            $this->subject->getFoldersInFolder('/folder/')
        );
    }

    public function testGetFoldersInFolderAppliesFilterCallbacks(): void
    {
        $this->webdavClient->method('getDescendantPaths')->willReturn($this->createListing());

        $filter = static fn (string $itemName) => 'sub1' === $itemName ? -1 : true;

        self::assertSame(['/folder/sub2/'], $this->subject->getFoldersInFolder('/folder/', 0, 0, false, [$filter]));
    }

    public function testCountFilesAndFoldersInFolder(): void
    {
        $this->webdavClient->method('getDescendantPaths')->willReturn($this->createListing());

        self::assertSame(2, $this->subject->countFilesInFolder('/folder/'));
        self::assertSame(2, $this->subject->countFoldersInFolder('/folder/'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function listingMethods(): array
    {
        return [
            'files' => ['getFilesInFolder'],
            'folders' => ['getFoldersInFolder'],
            'count files' => ['countFilesInFolder'],
            'count folders' => ['countFoldersInFolder'],
        ];
    }

    /**
     * @dataProvider listingMethods
     */
    public function testListingThrowsIfFolderCannotBeListed(string $method): void
    {
        $this->webdavClient->method('getDescendantPaths')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1795427385);
        $this->expectExceptionMessage('/missing/');
        $this->subject->{$method}('/missing/');
    }

    public function testRenameFolderReturnsEmptyMapIfListingAfterMoveFails(): void
    {
        $this->webdavClient->method('move')->willReturn(true);
        $this->webdavClient->method('getDescendantPaths')->willReturn(null);

        self::assertSame([], $this->subject->renameFolder('/parent/old/', 'new'));
    }

    public function testIsFolderEmptyDelegatesToClient(): void
    {
        $this->webdavClient->expects(self::once())->method('isFolderEmpty')->with('/folder/')->willReturn(true);

        self::assertTrue($this->subject->isFolderEmpty('/folder'));
    }

    // ---------------------------------------------------------------------------------------------
    // File operations
    // ---------------------------------------------------------------------------------------------

    public function testAddFileUploadsLocalFileAndRemovesIt(): void
    {
        $localFile = $this->createLocalFile('local contents', 'upload.txt');

        $this->webdavClient->expects(self::once())
            ->method('uploadFile')
            ->with('/folder/upload.txt', self::callback(static function ($stream): bool {
                return \is_resource($stream) && 'local contents' === \stream_get_contents($stream);
            }))
            ->willReturn(true);

        self::assertSame('/folder/upload.txt', $this->subject->addFile($localFile, '/folder/'));
        self::assertFileDoesNotExist($localFile);
    }

    public function testAddFileUsesNewFileNameAndCanKeepOriginal(): void
    {
        $localFile = $this->createLocalFile('local contents', 'upload.txt');

        $this->webdavClient->expects(self::once())
            ->method('uploadFile')
            ->with('/folder/renamed.txt')
            ->willReturn(true);

        self::assertSame('/folder/renamed.txt', $this->subject->addFile($localFile, '/folder', 'renamed.txt', false));
        self::assertFileExists($localFile);
    }

    public function testAddFileCanUploadReadOnlyLocalFile(): void
    {
        if (\function_exists('posix_geteuid') && 0 === \posix_geteuid()) {
            self::markTestSkipped('File permissions are not enforced for root.');
        }
        $localFile = $this->createLocalFile('read-only contents', 'readonly.txt');
        \chmod($localFile, 0400);

        $this->webdavClient->expects(self::once())
            ->method('uploadFile')
            ->with('/folder/readonly.txt', self::callback(static function ($stream): bool {
                return 'read-only contents' === \stream_get_contents($stream);
            }))
            ->willReturn(true);

        // Uploading only needs to read the file; nothing has to be removed afterwards.
        self::assertSame('/folder/readonly.txt', $this->subject->addFile($localFile, '/folder/', '', false));
    }

    public function testAddFileThrowsIfUploadFails(): void
    {
        $localFile = $this->createLocalFile('local contents', 'upload.txt');
        $this->webdavClient->method('uploadFile')->willReturn(false);

        $this->expectException(FileOperationErrorException::class);
        $this->expectExceptionCode(1643260717);
        $this->subject->addFile($localFile, '/folder/');
    }

    public function testAddFileThrowsIfLocalFileCannotBeOpened(): void
    {
        $this->webdavClient->expects(self::never())->method('uploadFile');

        $this->expectException(FileOperationErrorException::class);
        $this->expectExceptionCode(1795427381);

        // fopen() emits a warning before returning false; that is expected here.
        \set_error_handler(static fn (): bool => true, \E_WARNING);
        try {
            $this->subject->addFile('/does/not/exist.txt', '/folder/');
        } finally {
            \restore_error_handler();
        }
    }

    public function testCreateFileUploadsEmptyFile(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('uploadFile')
            ->with('/folder/new.txt', self::callback(static function ($stream): bool {
                return '' === \stream_get_contents($stream);
            }))
            ->willReturn(true);

        self::assertTrue($this->subject->createFile('new.txt', '/folder/'));
    }

    public function testCopyFileWithinStorage(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('copy')
            ->with('/a/file.txt', '/b/copy.txt')
            ->willReturn(true);

        self::assertSame('/b/copy.txt', $this->subject->copyFileWithinStorage('/a/file.txt', '/b', 'copy.txt'));
    }

    public function testMoveFileWithinStorage(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('move')
            ->with('/a/file.txt', '/b/moved.txt')
            ->willReturn(true);

        self::assertSame('/b/moved.txt', $this->subject->moveFileWithinStorage('/a/file.txt', '/b/', 'moved.txt'));
    }

    public function testRenameFileKeepsParentFolder(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('move')
            ->with('/a/file.txt', '/a/renamed.txt')
            ->willReturn(true);

        self::assertSame('/a/renamed.txt', $this->subject->renameFile('/a/file.txt', 'renamed.txt'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string>}>
     */
    public function failingFileOperations(): array
    {
        return [
            'copy' => ['copy', 'copyFileWithinStorage', ['/a/file.txt', '/b/', 'copy.txt']],
            'move' => ['move', 'moveFileWithinStorage', ['/a/file.txt', '/b/', 'moved.txt']],
            'rename' => ['move', 'renameFile', ['/a/file.txt', 'renamed.txt']],
        ];
    }

    /**
     * Like TYPO3's LocalDriver, the driver must not report a new identifier for a file that was
     * never created - FAL would otherwise index a file that does not exist.
     *
     * @dataProvider failingFileOperations
     * @param array<string> $arguments
     */
    public function testFileOperationsThrowIfServerOperationFails(
        string $clientMethod,
        string $driverMethod,
        array $arguments
    ): void {
        $this->webdavClient->method($clientMethod)->willReturn(false);

        $this->expectException(FileOperationErrorException::class);
        $this->subject->{$driverMethod}(...$arguments);
    }

    public function testDeleteFile(): void
    {
        $this->webdavClient->expects(self::once())->method('delete')->with('/a/file.txt')->willReturn(true);

        self::assertTrue($this->subject->deleteFile('a/file.txt'));
    }

    public function testGetFileContents(): void
    {
        $this->webdavClient->method('get')->with('/a/file.txt')->willReturn('contents');

        self::assertSame('contents', $this->subject->getFileContents('/a/file.txt'));
    }

    public function testDumpFileContentsEchoesContents(): void
    {
        $this->webdavClient->method('get')->willReturn('contents');

        $this->expectOutputString('contents');
        $this->subject->dumpFileContents('/a/file.txt');
    }

    public function testSetFileContentsReturnsNumberOfBytesWritten(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('uploadFile')
            ->with('/a/file.txt', self::callback(static function ($stream): bool {
                return 'new contents' === \stream_get_contents($stream);
            }))
            ->willReturn(true);

        self::assertSame(12, $this->subject->setFileContents('/a/file.txt', 'new contents'));
    }

    public function testSetFileContentsReturnsMinusOneIfUploadFails(): void
    {
        $this->webdavClient->method('uploadFile')->willReturn(false);

        self::assertSame(-1, $this->subject->setFileContents('/a/file.txt', 'new contents'));
    }

    public function testReplaceFileUploadsContentsOfLocalFile(): void
    {
        $localFile = $this->createLocalFile('replacement', 'replacement.txt');
        $this->webdavClient->expects(self::once())
            ->method('uploadFile')
            ->with('/a/file.txt', self::callback(static function ($stream): bool {
                return 'replacement' === \stream_get_contents($stream);
            }))
            ->willReturn(true);

        self::assertTrue($this->subject->replaceFile('/a/file.txt', $localFile));
    }

    public function testHashIsCalculatedFromFileContents(): void
    {
        $this->webdavClient->method('get')->with('/a/file.txt')->willReturn('contents');

        self::assertSame(\sha1('contents'), $this->subject->hash('/a/file.txt', 'sha1'));
        self::assertSame(\md5('contents'), $this->subject->hash('/a/file.txt', 'md5'));
    }

    public function testGetFileForLocalProcessingCreatesTemporaryFileWithSameExtension(): void
    {
        $this->webdavClient->method('get')->with('/a/image.jpg')->willReturn('jpeg data');

        $localPath = $this->subject->getFileForLocalProcessing('/a/image.jpg');
        $this->localFilesToRemove[] = $localPath;

        self::assertFileExists($localPath);
        self::assertStringEndsWith('.jpg', $localPath);
        self::assertSame('jpeg data', \file_get_contents($localPath));
    }

    public function testTemporaryFilesAreRemovedWhenDriverIsDestroyed(): void
    {
        $this->webdavClient->method('get')->willReturn('data');

        $localPath = $this->subject->getFileForLocalProcessing('/a/file.txt');
        $this->localFilesToRemove[] = $localPath;
        self::assertFileExists($localPath);

        unset($this->subject);
        \gc_collect_cycles();

        self::assertFileDoesNotExist($localPath);
    }

    // ---------------------------------------------------------------------------------------------
    // File and folder information
    // ---------------------------------------------------------------------------------------------

    public function testGetFileInfoByIdentifierCombinesServerAndLocalInformation(): void
    {
        $this->webdavClient->expects(self::once())
            ->method('getProperties')
            ->with('/folder/image.png')
            ->willReturn(['size' => 1234, 'mtime' => 1000, 'ctime' => 900, 'mimetype' => 'image/png']);

        self::assertSame(
            [
                'size' => 1234,
                'mtime' => 1000,
                'ctime' => 900,
                'mimetype' => 'image/png',
                'name' => 'image.png',
                'extension' => 'png',
                'identifier' => '/folder/image.png',
                'identifier_hash' => \sha1('/folder/image.png'),
                'storage' => self::STORAGE_UID,
                // Same as TYPO3's LocalDriver: hashIdentifier() canonicalizes the parent folder
                // identifier as a file identifier, which strips the trailing slash.
                'folder_hash' => \sha1('/folder'),
            ],
            $this->subject->getFileInfoByIdentifier('folder/image.png')
        );
    }

    public function testGetFileInfoByIdentifierDoesNotQueryServerForLocalProperties(): void
    {
        $this->webdavClient->expects(self::never())->method('getProperties');

        self::assertSame(
            ['name' => 'file.txt', 'identifier' => '/folder/file.txt'],
            $this->subject->getFileInfoByIdentifier('/folder/file.txt', ['name', 'identifier'])
        );
    }

    public function testGetFileInfoByIdentifierThrowsForUnavailableServerProperty(): void
    {
        $this->webdavClient->method('getProperties')->willReturn([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1643142896);
        $this->subject->getFileInfoByIdentifier('/folder/file.txt', ['size']);
    }

    public function testGetFolderInfoByIdentifier(): void
    {
        self::assertSame(
            ['identifier' => '/folder/sub/', 'name' => 'sub', 'storage' => self::STORAGE_UID],
            $this->subject->getFolderInfoByIdentifier('/folder/sub')
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * @param array<string, string> $configuration
     */
    private function createDriver(WebdavClient $client, array $configuration): WebdavDriver
    {
        $factory = $this->createMock(WebdavClientFactory::class);
        $factory->method('createWebdavClient')->willReturn($client);
        GeneralUtility::addInstance(WebdavClientFactory::class, $factory);

        $driver = new WebdavDriver($configuration);
        $driver->setStorageUid(self::STORAGE_UID);
        $driver->processConfiguration();
        $driver->initialize();

        return $driver;
    }

    /**
     * @return array<string, string>
     */
    private function createListing(): array
    {
        return [
            '/folder/' => 'directory',
            '/folder/sub2/' => 'directory',
            '/folder/b.txt' => 'file',
            '/folder/sub1/' => 'directory',
            '/folder/a.txt' => 'file',
        ];
    }

    private function createLocalFile(string $contents, string $name): string
    {
        $directory = \sys_get_temp_dir() . '/fal_webdav2_test_' . \bin2hex(\random_bytes(4));
        \mkdir($directory);
        $path = $directory . '/' . $name;
        \file_put_contents($path, $contents);

        $this->localFilesToRemove[] = $path;
        $this->localDirectoriesToRemove[] = $directory;

        return $path;
    }
}
