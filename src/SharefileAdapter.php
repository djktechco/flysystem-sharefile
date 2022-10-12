<?php

namespace Kapersoft\FlysystemSharefile;

use Exception;
use Throwable;
use League\Flysystem\Config;
use Kapersoft\ShareFile\Client;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToRetrieveMetadata;

/**
 * Flysysten ShareFile Adapter.
 *
 * @author   Jan Willem Kaper <kapersoft@gmail.com>
 * @license  MIT (see License.txt)
 *
 * @link     http://github.com/kapersoft/flysystem-sharefile
 */
class SharefileAdapter implements FilesystemAdapter
{

    /** ShareFile access control constants */
    const CAN_ADD_FOLDER = 'CanAddFolder';
    const ADD_NODE = 'CanAddNode';
    const CAN_VIEW = 'CanView';
    const CAN_DOWNLOAD = 'CanDownload';
    const CAN_UPLOAD = 'CanUpload';
    const CAN_SEND = 'CanSend';
    const CAN_DELETE_CURRENT_ITEM = 'CanDeleteCurrentItem';
    const CAN_DELETE_CHILD_ITEMS = 'CanDeleteChildItems';
    const CAN_MANAGE_PERMISSIONS = 'CanManagePermissions';
    const CAN_CREATEOFFICE_DOCUMENTS = 'CanCreateOfficeDocuments';

    /**
     * ShareFile Client.
     *
     * @var  \Kapersoft\ShareFile\Client;
     * */
    protected $client;

    /**
     * Indicated if metadata should include the ShareFile item array.
     *
     * @var  bool
     * */
    protected $returnShareFileItem;

    /**
     * Path prefix for files.
     *
     * @var string
     */
    protected $pathPrefix;

    /**
     * SharefileAdapter constructor.
     *
     * @param Client $client              Instance of Kapersoft\ShareFile\Client
     * @param string $prefix              Folder prefix
     * @param bool   $returnShareFileItem Indicated if getMetadata/listContents should return ShareFile item array.
     *
     * @param string $prefix
     */
    public function __construct(Client $client, string $prefix = '', bool $returnShareFileItem = false)
    {
        $this->client = $client;

        $this->returnShareFileItem = $returnShareFileItem;

        $this->setPathPrefix($prefix);
    }

    /**
     * Sets path prefix for files. Acts as root directory for all files and folders.
     *
     * @param $prefix
     * @return void
     */
    public function setPathPrefix($prefix)
    {
        $this->pathPrefix = trim($prefix, '/');
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $path): string
    {
        return $this->readWithMeta($path)['contents'] ?? '';
    }

    /**
     * Reads file and maps info to array.
     *
     * @param $path
     * @return array
     */
    public function readWithMeta($path)
    {
        try {
            if (! $item = $this->getItemByPath($path)) {
                throw new Exception('Item could not be found.');
            }

            if (! $this->checkAccessControl($item, self::CAN_DOWNLOAD)) {
                throw new Exception('Access forbidden.');
            }

            $contents = $this->client->getItemContents($item['Id']);

            return $this->mapItemInfo($item, Util::dirname($path), $contents);
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        return $this->readStreamWithMeta($path)['stream'];
    }

    /**
     * Reads stream and maps info to array.
     *
     * @param $path
     * @return array
     */
    public function readStreamWithMeta($path)
    {
        if (! $item = $this->getItemByPath($path)) {
            throw new Exception('Item could not be found.');
        }

        if (! $this->checkAccessControl($item, self::CAN_DOWNLOAD)) {
            throw new Exception('Access forbidden.');
        }

        $url = $this->client->getItemDownloadUrl($item['Id']);

        $stream = fopen($url['DownloadUrl'], 'r');

        return $this->mapItemInfo($item, Util::dirname($path), null, $stream);
    }


    /**
     * {@inheritdoc}
     */
    public function listContents(string $directory = '', $recursive = false): iterable
    {
        if (! $item = $this->getItemByPath($directory)) {
            return [];
        }

        return $this->buildItemList($item, $directory, $recursive);
    }


    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        if (! $item = $this->getItemByPath($path)) {
            return false;
        }
        $metadata = $this->mapItemInfo($item, Util::dirname($path));

        if (in_array($path, ['/', ''], true)) {
            $metadata['path'] = $path;
        }

        return $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getmetaData($path);
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config = null): void
    {
        try {
            $this->uploadFile($path, $contents, true);
        } catch (Throwable $exception) {
            throw new UnableToWriteFile($path, $exception->getCode(), $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream(string $path, $resource, Config $config = null): void
    {
        $this->uploadFile($path, $resource, true);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config = null)
    {
        return $this->uploadFile($path, $contents, true);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config = null)
    {
        return $this->uploadFile($path, $resource, true);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        if (! $targetFolderItem = $this->getItemByPath(Util::dirname($newpath))) {
            return false;
        }

        if (! $this->checkAccessControl($targetFolderItem, self::CAN_UPLOAD)) {
            return false;
        }

        if (! $item = $this->getItemByPath($path)) {
            return false;
        }

        $data = [
            'FileName' =>  basename($newpath),
            'Name' =>  basename($newpath),
            'Parent' =>  [
                'Id' => $targetFolderItem['Id'],
            ],
        ];

        $this->client->updateItem($item['Id'], $data);

        return is_array($this->has($newpath));
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $source, string $destination, $config = []): void
    {
        try {
            if (! $targetFolderItem = $this->getItemByPath(Util::dirname($destination))) {
                throw new Exception("The file could not be copied because the destination, $destination, does not exist.");
            }

            if (! $this->checkAccessControl($targetFolderItem, self::CAN_UPLOAD)) {
                throw new Exception("The file could not be copied because the user does not have access to the target folder, $destination.");
            }

            if (! $item = $this->getItemByPath($source)) {
                throw new Exception("The file could not be copied because the source file, at $source, does not exist.");
            }

            if (strcasecmp(Util::dirname($source), Util::dirname($destination)) != 0 &&
                strcasecmp(basename($source), basename($destination)) == 0) {
                $this->client->copyItem($targetFolderItem['Id'], $item['Id'], true);
            } else {
                $contents = $this->client->getItemContents($item['Id']);
                $this->uploadFile($destination, $contents, true);
            }
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->deleteItem($source);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function directoryExists(string $path): bool
    {
        return is_array($this->has($path));
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path, Config $config): void
    {
        try {
            if (! $this->createDir($path)) {
                throw new Exception('The directory could not be created.');
            }
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function visibility(string $path): FileAttributes
    {
        // not supported so just skip go ahead and throw the exception.
        try {
            throw new Exception(get_class($this) . ' does not support visibility. Path: ' . $path);

            return new FileAttributes($path);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::visibility($path, '', $exception);
        }
    }

    public function mimeType(string $path): FileAttributes
    {
        // TODO: Implement mimeType() method.
    }

    /**
     * {@inheritdoc}
     */
    public function fileExists(string $path): bool
    {
        return is_array($this->has($path));
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToRetrieveMetadata::visibility(get_class($this) . ' does not support visibility. Path: ' . $path);
    }

    public function fileSize(string $path): FileAttributes
    {
        // TODO: Implement fileSize() method.
    }

    public function lastModified(string $path): FileAttributes
    {
        // TODO: Implement lastModified() method.
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path): void
    {
        $this->deleteItem($path);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory($path): void
    {
        $this->deleteItem($path);
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname)
    {
        $parentFolder = Util::dirname($dirname);
        $folder = basename($dirname);

        if (! $parentFolderItem = $this->getItemByPath($parentFolder)) {
            return false;
        }

        if (! $this->checkAccessControl($parentFolderItem, self::CAN_ADD_FOLDER)) {
            return false;
        }

        $this->client->createFolder($parentFolderItem['Id'], $folder, $folder, true);

        return $this->has($dirname);
    }

    /**
     * {@inheritdoc}
     */
    public function put($path, $contents)
    {
        return $this->uploadFile($path, $contents, true);
    }

    /**
     * {@inheritdoc}
     */
    public function readAndDelete($path)
    {
        if (! $item = $this->getItemByPath($path)) {
            return false;
        }

        if (! $this->checkAccessControl($item, self::CAN_DOWNLOAD) ||
            ! $this->checkAccessControl($item, self::CAN_DELETE_CURRENT_ITEM)) {
            return false;
        }

        $itemContents = $this->client->getItemContents($item['Id']);

        $this->delete($path);

        return $itemContents;
    }

    /**
     * Returns ShareFile client.
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Upload a file to ShareFile.
     *
     * @param string          $path      File path
     * @param resource|string $contents  Resource or contents of the file
     * @param bool            $overwrite Overwrite file when it exists
     *
     * @return array|false
     */
    protected function uploadFile(string $path, $contents, bool $overwrite = false)
    {
        if (! $parentFolderItem = $this->getItemByPath(Util::dirname($path))) {
            return false;
        }

        if (! $this->checkAccessControl($parentFolderItem, self::CAN_UPLOAD)) {
            return false;
        }

        if (is_string($contents)) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $contents);
            rewind($stream);
        } else {
            $stream = $contents;
        }

        $this->client->uploadFileStreamed($stream, $parentFolderItem['Id'], basename($path), false, $overwrite);

        if ($metadata = $this->getMetadata($path)) {
            if (is_string($contents)) {
                $metadata['contents'] = $contents;
            }

            return $metadata;
        }

        return false;
    }

    /**
     * Map ShareFile item to FlySystem metadata.
     *
     * @param array       $item     ShareFile item
     * @param string      $path     Base path
     * @param string|null $contents Contents of the file (optional)
     * @param mixed|null  $stream   Resource handle of the file (optional)
     *
     * @return array
     */
    protected function mapItemInfo(array $item, string $path = '', string $contents = null, $stream = null): array
    {
        $timestamp = $item['ClientModifiedDate'] ?? $item['ClientCreatedDate'] ??
            $item['CreationDate'] ?? $item['ProgenyEditDate'] ?? '';
        $timestamp = ! empty($timestamp) ? strtotime($timestamp) : false;

        if ($path == '.') {
            $path = '';
        }
        $path = trim($path.'/'.$item['FileName'], '/');

        if ($this->isShareFileApiModelsFile($item)) {
            $mimetype = Util::guessMimeType($item['FileName'], $contents);
            $type = 'file';
        } else {
            $mimetype = 'inode/directory';
            $type = 'dir';
        }

        return array_merge(
            [
                'timestamp' => $timestamp,
                'path' => $path,
                'mimetype' => $mimetype,
                'dirname' => pathinfo($path, PATHINFO_DIRNAME),
                'extension' => pathinfo($item['FileName'], PATHINFO_EXTENSION),
                'filename' => pathinfo($item['FileName'], PATHINFO_FILENAME),
                'basename' => pathinfo($item['FileName'], PATHINFO_FILENAME),
                'type' => $type,
                'size' => $item['FileSizeBytes'],
                'contents' =>  ! empty($contents) ? $contents : false,
                'stream' => ! empty($stream) ? $stream : false,
            ],
            $this->returnShareFileItem ? ['sharefile_item' => $item] : []
        );
    }

    /**
     * Map list of ShareFile items with metadata.
     *
     * @param array  $items List of ShareFile items
     * @param string $path  Base path
     *
     * @return array
     */
    protected function mapItemList(array $items, string $path):array
    {
        return array_map(
            function ($item) use ($path) {
                return $this->mapItemInfo($item, $path);
            },
            $items
        );
    }

    /**
     * Build metadata list from ShareFile item.
     *
     * @param array  $item       ShareFile item
     * @param string $path       Path of the given ShareFile item
     * @param bool   $recursive  Recursive mode
     *
     * @return array
     */
    protected function buildItemList(array $item, string $path, $recursive = false):array
    {
        if ($this->isShareFileApiModelsFile($item)) {
            return [];
        }

        $children = $this->client->getItemById($item['Id'], true);

        if (! isset($children['Children']) || count($children['Children']) < 1) {
            return [];
        }

        $children = $this->removeAllExceptFilesAndFolders($children['Children']);

        $itemList = $this->mapItemList($children, $path);

        if ($recursive) {
            foreach ($children as $child) {
                $path = $path.'/'.$child['FileName'];

                $itemList = array_merge(
                    $itemList,
                    $this->buildItemList($child, $path, true)
                );
            }
        }

        return $itemList;
    }

    /**
     * Remove all items except files and folders in the given array of ShareFile items.
     *
     * @param array $items Array of ShareFile items
     *
     * @return array
     */
    protected function removeAllExceptFilesAndFolders(array $items):array
    {
        return array_filter(
            $items,
            function ($item) {
                return $this->isShareFileApiModelsFolder($item) || $this->isShareFileApiModelsFile($item);
            }
        );
    }

    /**
     * Check if ShareFile item is a ShareFile.Api.Models.Folder type.
     *
     * @param array $item
     *
     * @return bool
     */
    protected function isShareFileApiModelsFolder(array $item):bool
    {
        return $item['odata.type'] == 'ShareFile.Api.Models.Folder';
    }

    /**
     * Check if ShareFile item is a ShareFile.Api.Models.File type.
     *
     * @param array $item
     *
     * @return bool
     */
    protected function isShareFileApiModelsFile(array $item):bool
    {
        return $item['odata.type'] == 'ShareFile.Api.Models.File';
    }

    /**
     * Get ShareFile item using path.
     *
     * @param string $path Path of the requested file
     *
     * @return array|false
     *
     * @throws Exception
     */
    protected function getItemByPath(string $path)
    {
        if ($path == '.') {
            $path = '';
        }
        $path = '/'.trim($this->applyPathPrefix($path), '/');

        try {
            $item = $this->client->getItemByPath($path);
            if ($this->isShareFileApiModelsFolder($item) || $this->isShareFileApiModelsFile($item)) {
                return $item;
            }
        } catch (exception $e) {
            return false;
        }

        return false;
    }

    /**
     * Check access control of a ShareFile item.
     *
     * @param array  $item ShareFile item
     * @param string $rule Access rule
     *
     * @return bool
     */
    protected function checkAccessControl(array $item, string $rule):bool
    {
        if ($this->isShareFileApiModelsFile($item)) {
            $item = $this->client->getItemById($item['Parent']['Id']);
            if ($rule == self::CAN_DELETE_CURRENT_ITEM) {
                $rule = self::CAN_DELETE_CHILD_ITEMS;
            }
        }

        if (isset($item['Info'][$rule])) {
            return $item['Info'][$rule] == 1;
        } else {
            return false;
        }
    }

    /**
     * Files and directories are deleted in the same manner, by providing a path, so this method handles both.
     *
     * @param string $path
     * @return void
     */
    protected function deleteItem(string $path)
    {
        try {
            if (! $item = $this->getItemByPath($path)) {
                throw new Exception('The directory does not exist.');
            }

            if (! $this->checkAccessControl($item, self::CAN_DELETE_CURRENT_ITEM)) {
                throw new Exception('You do not have permission to delete the directory.');
            }

            $this->client->deleteItem($item['Id']);

            if ($this->has($path) !== false) {
                throw new Exception('The directory could not be deleted.');
            }

        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, '', $exception);
        }
    }

    /**
     * Combines path prefix to $path.
     *
     * @param $path
     * @return string
     */
    protected function applyPathPrefix($path): string
    {
        return $this->pathPrefix . '/' . trim($path, '/');
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        return $this->getMetadata($path);
    }
}
