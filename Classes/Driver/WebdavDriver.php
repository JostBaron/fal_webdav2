<?php

declare(strict_types=1);

namespace Jbaron\FalWebdav\Driver;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceStorageInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class WebdavDriver extends AbstractHierarchicalFilesystemDriver
{
    public const DRIVER_KEY = 'Jbaron.FalWebdav';

    private const CONFIG_WEBDAV_URL = 'webdav_url';
    private const CONFIG_PUBLIC_URL = 'public_url';

    private const ROOT_FOLDER_ID = '/';
    private const DEFAULT_FOLDER_ID = '/user_upload/';

    private LoggerInterface $logger;
    private WebdavClient $webdavClient;

    /**
     * @var array<string>
     */
    private array $tempfileNames = [];

    /**
     * DatabaseDriver constructor.
     *
     * @param array $configuration
     */
    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);

        $this->capabilities = ResourceStorageInterface::CAPABILITY_BROWSABLE
            | ResourceStorageInterface::CAPABILITY_PUBLIC
            | ResourceStorageInterface::CAPABILITY_WRITABLE;

        $this->webdavClientFactory = GeneralUtility::makeInstance(WebdavClientFactory::class);
    }

    public function __destruct()
    {
        foreach ($this->tempfileNames as $tempfileName) {
            @unlink($tempfileName);
        }
    }

    public function processConfiguration(): void
    {
        $this->webdavClient = $this->webdavClientFactory->createWebdavClient(
            $this->configuration[self::CONFIG_WEBDAV_URL],
            $this->configuration[self::CONFIG_PUBLIC_URL]
        );
    }

    public function initialize(): void
    {
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    public function mergeConfigurationCapabilities($capabilities): int
    {
        $this->capabilities &= $capabilities;
        return $this->capabilities;
    }

    public function isCaseSensitiveFileSystem(): bool
    {
        return true;
    }

    public function getRootLevelFolder(): string
    {
        return $this->canonicalizeAndCheckFolderIdentifier(static::ROOT_FOLDER_ID);
    }

    public function getDefaultFolder(): string
    {
        return $this->canonicalizeAndCheckFolderIdentifier(static::DEFAULT_FOLDER_ID);
    }

    public function getPublicUrl($identifier): string
    {
        return $this->webdavClient->getPublicUrl($this->canonicalizeAndCheckFileIdentifier($identifier));
    }

    public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = false): string
    {
        $newFolderPath = $this->getFolderIdentifierFromParentFolderAndFolderName(
            $parentFolderIdentifier,
            $newFolderName
        );

        $newFolderPath = $this->webdavClient->createFolder($newFolderPath);
        if (null === $newFolderPath) {
            throw new FileOperationErrorException(
                'Could not create folder.',
                1669558067
            );
        }
        return $newFolderPath;
    }

    public function renameFolder($folderIdentifier, $newName): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $oldFolderParentIdentifier = $this->getParentFolderIdentifierOfIdentifier($folderIdentifier);
        $newFolderIdentifier = $this->getFolderIdentifierFromParentFolderAndFolderName(
            $oldFolderParentIdentifier,
            $newName
        );

        $success = $this->webdavClient->move($folderIdentifier, $newFolderIdentifier);
        if (!$success) {
            throw new FileOperationErrorException(
                'Could not move folder.',
                1643145995
            );
        }

        $identifierMap = [];
        foreach ($this->webdavClient->getDescendantPaths($newFolderIdentifier, true) as $path => $pathType) {
            $oldIdentifier = $folderIdentifier . \substr($path, \strlen($newFolderIdentifier));
            $identifierMap[$oldIdentifier] = $path;
        }
        return $identifierMap;
    }

    public function deleteFolder($folderIdentifier, $deleteRecursively = false): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($deleteRecursively || $this->isFolderEmpty($folderIdentifier)) {
            return $this->webdavClient->delete($folderIdentifier);
        }
        return false;
    }

    public function fileExists($fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        if (self::ROOT_FOLDER_ID === $fileIdentifier) {
            return false;
        }

        return $this->webdavClient->pathExists($fileIdentifier);
    }

    public function folderExists($folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if (self::ROOT_FOLDER_ID === $folderIdentifier) {
            return true;
        }
        return $this->webdavClient->pathExists($folderIdentifier);
    }

    public function isFolderEmpty($folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        return $this->webdavClient->isFolderEmpty($folderIdentifier);
    }

    public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = true): string
    {
        $fileName = '' === $newFileName ? \basename($localFilePath) : $newFileName;
        $newFileIdentifier = $this->getFileIdentifierFromParentFolderAndFileName($targetFolderIdentifier, $fileName);

        $fileHandle = \fopen($localFilePath, 'r+');
        if (false === $fileHandle) {
            $this->logger->error(
                'Could not open local file for storing in FAL.',
                [
                    'localFilePath' => $localFilePath,
                ]
            );
        }
        $result = $this->webdavClient->uploadFile($newFileIdentifier, $fileHandle);
        if (!$result) {
            throw new FileOperationErrorException(
                'Uploading local file failed.',
                1643260717
            );
        }

        $closed = \fclose($fileHandle);
        if (!$closed) {
            $this->logger->notice(
                'Failed to close local file after upload',
                [
                    'localFilePath' => $localFilePath,
                ]
            );
        }

        if ($removeOriginal) {
            if (!\is_writable($localFilePath)) {
                throw new FileOperationErrorException(
                    \sprintf(
                        'Cannot write file "%s" to remove it from the file system after adding it to the storage.',
                        $localFilePath
                    ),
                    1578465610
                );
            }
            \unlink($localFilePath);
        }

        return $newFileIdentifier;
    }

    public function createFile($fileName, $parentFolderIdentifier): bool
    {
        $newFileIdentifier = $this->getFileIdentifierFromParentFolderAndFileName($parentFolderIdentifier, $fileName);

        $fileHandle = \fopen('php://memory', 'rw');
        if (false === $fileHandle) {
            $this->logger->error('Could not open memory stream containing nothing for storing it in FAL.');
            return false;
        }
        $written = \fwrite($fileHandle, '');
        if (false === $written) {
            $this->logger->error('Could not write empty string to memory stream.');
            return false;
        }
        $seeked = \fseek($fileHandle, 0);
        if (-1 === $seeked) {
            $this->logger->error('Could not seek memory stream.');
            return false;
        }

        $result = $this->webdavClient->uploadFile($newFileIdentifier, $fileHandle);

        $closed = \fclose($fileHandle);
        if (!$closed) {
            $this->logger->notice('Failed to close local file after upload');
        }
        return $result;
    }

    public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $newFileIdentifier = $this->getFileIdentifierFromParentFolderAndFileName($targetFolderIdentifier, $fileName);

        $this->webdavClient->copy($fileIdentifier, $newFileIdentifier);

        return $newFileIdentifier;
    }

    public function renameFile($fileIdentifier, $newName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        $filesDirectory = $this->canonicalizeAndCheckFolderIdentifier(
            $this->getParentFolderIdentifierOfIdentifier($fileIdentifier)
        );
        $newFileIdentifier = $this->getFileIdentifierFromParentFolderAndFileName($filesDirectory, $newName);

        $this->webdavClient->move($fileIdentifier, $newFileIdentifier);

        return $newFileIdentifier;
    }

    public function replaceFile($fileIdentifier, $localFilePath): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        return -1 !== $this->setFileContents($fileIdentifier, \file_get_contents($localFilePath));
    }

    public function deleteFile($fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        return $this->webdavClient->delete($fileIdentifier);
    }

    public function hash($fileIdentifier, $hashAlgorithm): string
    {
        return \hash($hashAlgorithm, $this->getFileContents($fileIdentifier));
    }

    public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $newFileIdentifier = $this->getFileIdentifierFromParentFolderAndFileName($targetFolderIdentifier, $newFileName);

        $this->webdavClient->move($fileIdentifier, $newFileIdentifier);

        return $newFileIdentifier;
    }

    public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName): array
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $targetFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier);
        $targetFolder = $this->getFolderIdentifierFromParentFolderAndFolderName($targetFolderIdentifier, $newFolderName);

        $success = $this->webdavClient->move($sourceFolderIdentifier, $targetFolder);

        if (!$success) {
            throw new FileOperationErrorException(
                'Could not move folder.',
                1643145995
            );
        }

        $identifierMap = [];
        foreach ($this->webdavClient->getDescendantPaths($targetFolder, true) as $path => $pathType) {
            $oldIdentifier = $sourceFolderIdentifier . \substr($path, \strlen($targetFolder));
            $identifierMap[$oldIdentifier] = $path;
        }
        return $identifierMap;
    }

    public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName): bool
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $targetFolder = $this->getFolderIdentifierFromParentFolderAndFolderName(
            $targetFolderIdentifier,
            $newFolderName
        );

        return $this->webdavClient->copy($sourceFolderIdentifier, $targetFolder);
    }

    public function getFileContents($fileIdentifier): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        return $this->webdavClient->get($fileIdentifier);
    }

    public function setFileContents($fileIdentifier, $contents): int
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        $fileHandle = \fopen('php://memory', 'rw');
        if (false === $fileHandle) {
            $this->logger->error('Could not open memory stream for uploading it to webdav.');
            return -1;
        }
        $written = \fwrite($fileHandle, $contents);
        if (false === $written || $written !== \strlen($contents)) {
            $this->logger->error('Could not write string to memory stream.');
            return -1;
        }
        $seeked = \fseek($fileHandle, 0);
        if (-1 === $seeked) {
            $this->logger->error('Could not seek memory stream.');
            return -1;
        }

        $result = $this->webdavClient->uploadFile($fileIdentifier, $fileHandle);
        if (!$result) {
            $this->logger->notice('Failed to upload file contents.');
            return -1;
        }

        $closed = \fclose($fileHandle);
        if (!$closed) {
            $this->logger->notice('Failed to close local file after upload');
        }
        return $written;
    }

    public function fileExistsInFolder($fileName, $folderIdentifier): bool
    {
        $fileIdentifier = $this->getFileIdentifierFromParentFolderAndFileName($folderIdentifier, $fileName);
        return $this->fileExists($fileIdentifier);
    }

    public function folderExistsInFolder($folderName, $folderIdentifier): bool
    {
        $completeFolderIdentifier = $this->getFolderIdentifierFromParentFolderAndFolderName(
            $folderIdentifier,
            $folderName
        );

        return $this->folderExists($completeFolderIdentifier);
    }

    public function getFileForLocalProcessing($fileIdentifier, $writable = true): string
    {
        $fileContents = $this->getFileContents($fileIdentifier);
        $temporaryFileName = $this->getTemporaryFileName($fileIdentifier);
        $result = \file_put_contents($temporaryFileName, $fileContents);
        if (false === $result) {
            $this->logger->error(
                'Failed to write file to local file for processing.',
                [
                    'temporaryFileName' => $temporaryFileName,
                ]
            );
            throw new FileOperationErrorException(
                'Failed to write file to local file for processing.',
                1643140785
            );
        }

        $this->logger->debug(
            'Got file for local processing',
            [
                'file' => $fileIdentifier,
                'tempPath' => $temporaryFileName,
                'downloadedSize' => \filesize($temporaryFileName),
            ]
        );

        return $temporaryFileName;
    }

    public function getPermissions($identifier): array
    {
        return [
            'r' => true,
            'w' => true,
        ];
    }

    public function dumpFileContents($identifier): void
    {
        echo $this->getFileContents($identifier);
    }

    public function isWithin($folderIdentifier, $identifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        return GeneralUtility::isFirstPartOfStr($identifier, $folderIdentifier);
    }

    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = []): array
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        return $this->extractFileInformation($fileIdentifier, $propertiesToExtract);
    }

    public function getFolderInfoByIdentifier($folderIdentifier): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        $folderInfo = [
            'identifier' => $folderIdentifier,
            'name' => \basename($folderIdentifier),
            'storage' => $this->storageUid
        ];

        $this->logger->debug('Folder info', ['folderId' => $folderIdentifier, 'info' => $folderInfo]);

        return $folderInfo;
    }

    public function getFileInFolder($fileName, $folderIdentifier): string
    {
        return $this->getFileIdentifierFromParentFolderAndFileName($folderIdentifier, $fileName);
    }

    public function getFilesInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $filenameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ): array {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        $filePaths = [];
        foreach ($this->webdavClient->getDescendantPaths($folderIdentifier, $recursive) as $path => $pathType) {
            if ('file' !== $pathType || $path === $folderIdentifier) {
                continue;
            }
            $entryName = \trim(\basename($path), '/');
            $entryFolderIdentifier = $this->getParentFolderIdentifierOfIdentifier($path);
            foreach ($filenameFilterCallbacks as $folderNameFilterCallback) {
                if (-1 === $folderNameFilterCallback($entryName, $path, $entryFolderIdentifier, [], $this)) {
                    continue 2;
                }
            }

            $filePaths[] = $path;
        }

        \sort(
            $filePaths,
            $sortRev ? \SORT_DESC : \SORT_ASC
        );

        return $filePaths;
    }

    public function getFolderInFolder($folderName, $folderIdentifier): string
    {
        return $this->getFolderIdentifierFromParentFolderAndFolderName($folderIdentifier, $folderName);
    }

    public function getFoldersInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $folderNameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ): array {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        $folderPaths = [];
        foreach ($this->webdavClient->getDescendantPaths($folderIdentifier, $recursive) as $path => $pathType) {
            if ('directory' !== $pathType || $path === $folderIdentifier) {
                continue;
            }
            $entryName = \trim(\basename($path), '/');
            $entryFolderIdentifier = $this->getParentFolderIdentifierOfIdentifier($path);
            foreach ($folderNameFilterCallbacks as $folderNameFilterCallback) {
                if (-1 === $folderNameFilterCallback($entryName, $path, $entryFolderIdentifier, [], $this)) {
                    continue 2;
                }
            }

            $folderPaths[] = $path;
        }

        \sort(
            $folderPaths,
            $sortRev ? \SORT_DESC : \SORT_ASC
        );

        return $folderPaths;
    }

    public function countFilesInFolder(
        $folderIdentifier,
        $recursive = false,
        array $filenameFilterCallbacks = []
    ): int {
        return \count(
            $this->getFilesInFolder($folderIdentifier, 0, 0, $recursive, $filenameFilterCallbacks)
        );
    }

    public function countFoldersInFolder(
        $folderIdentifier,
        $recursive = false,
        array $folderNameFilterCallbacks = []
    ): int {
        return \count(
            $this->getFoldersInFolder($folderIdentifier, 0, 0, $recursive, $folderNameFilterCallbacks)
        );
    }

    private function getFileIdentifierFromParentFolderAndFileName(
        string $parentFolderIdentifier,
        string $filename
    ): string {
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $newFileIdentifier = \rtrim($parentFolderIdentifier, '/') . '/' . $filename;
        return $this->canonicalizeAndCheckFileIdentifier($newFileIdentifier);
    }

    private function getFolderIdentifierFromParentFolderAndFolderName(
        string $parentFolderIdentifier,
        string $folderName
    ): string {
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $newFolderIdentifier = \rtrim($parentFolderIdentifier, '/') . '/' . \trim($folderName, '/') . '/';
        return $this->canonicalizeAndCheckFolderIdentifier($newFolderIdentifier);
    }

    /**
     * Creates a new temporary file and returns the path to it. Adds it to the list of files to be
     * deleted when the driver is destructed.
     *
     * @param string $fileIdentifier
     *
     * @return string
     *
     * @throws FileOperationErrorException
     */
    private function getTemporaryFileName(string $fileIdentifier): string
    {
        $temporaryFileName = \tempnam(\sys_get_temp_dir(), 'typo3_fal_webdav');
        if (false === $temporaryFileName) {
            throw new FileOperationErrorException(
                'Could not create temporary file in WebDAV FAL driver.',
                1643140437
            );
        }

        // Add file extension, otherwise the file will not be processed because the extension
        // is not in the list of whitelisted extensions.
        $newTemporaryFilename = $temporaryFileName . '.' . \pathinfo($fileIdentifier, \PATHINFO_EXTENSION);
        if (\file_exists($newTemporaryFilename)) {
            throw new FileOperationErrorException(
                'Could not rename temporary file in WebDAV FAL driver to contain the correct extension, '
                . 'because a file with the correct extension already exists.',
                1643140446
            );
        }
        $renameResult = \rename($temporaryFileName, $newTemporaryFilename);
        if (false === $renameResult) {
            throw new FileOperationErrorException(
                'Could not rename temporary file in WebDAV FAL driver to contain the correct extension.',
                1643140455
            );
        }

        $this->tempfileNames[] = $newTemporaryFilename;

        return $newTemporaryFilename;
    }

    /**
     * Extracts information about a file from the filesystem.
     *
     * @param string $identifier
     * @param string[] $propertiesToExtract array of properties which should be returned, if empty all will be extracted
     *
     * @return array
     *
     * @throws ResourceDoesNotExistException
     * @throws FileOperationErrorException
     */
    private function extractFileInformation(string $identifier, array $propertiesToExtract = []): array
    {
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);

        if (empty($propertiesToExtract)) {
            $propertiesToExtract = [
                'size', 'mtime', 'ctime', 'mimetype', 'name',
                'extension', 'identifier', 'identifier_hash', 'storage', 'folder_hash',
            ];
        }

        $onlineProperties = [];
        // Only load online properties if one of them is needed.
        if ([] !== \array_intersect($propertiesToExtract, ['size', 'mtime', 'ctime', 'mimetype'])) {
            $onlineProperties = $this->webdavClient->getProperties($identifier);
        }

        $fileInformation = [];
        foreach ($propertiesToExtract as $property) {
            $fileInformation[$property] = $this->getSpecificFileInformation($identifier, $onlineProperties, $property);
        }
        $this->logger->debug(
            'Got file information',
            [
                'fileIdentifier' => $identifier,
                'fileInformation' => $fileInformation,
            ]
        );
        return $fileInformation;
    }

    /**
     * Extracts a specific FileInformation from the FileSystems.
     *
     * @param string $identifier
     * @param array $onlineProperties
     * @param string $property
     *
     * @return bool|int|string
     *
     * @throws FileOperationErrorException
     */
    private function getSpecificFileInformation(string $identifier, array $onlineProperties, string $property)
    {
        if (\array_key_exists($property, $onlineProperties)) {
            return $onlineProperties[$property];
        }

        switch ($property) {
            case 'name':
                return \basename($identifier);
            case 'extension':
                return PathUtility::pathinfo($identifier, \PATHINFO_EXTENSION);
            case 'identifier':
                return $identifier;
            case 'storage':
                return $this->storageUid;
            case 'identifier_hash':
                return $this->hashIdentifier($identifier);
            case 'folder_hash':
                $folderIdentifier = $this->getParentFolderIdentifierOfIdentifier($identifier);
                return $this->hashIdentifier($folderIdentifier);
            default:
                throw new \InvalidArgumentException(
                    \sprintf('The information "%s" is not available for files from the webdav storage.', $property),
                    1643142896
                );
        }
    }
}
