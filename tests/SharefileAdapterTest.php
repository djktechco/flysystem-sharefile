<?php

namespace Kapersoft\FlysystemSharefile\Tests;

use Faker\Factory;
use Faker\Generator;
use Prophecy\Argument;
use League\Flysystem\Config;
use org\bovigo\vfs\vfsStream;
use GuzzleHttp\Psr7\Response;
use Kapersoft\ShareFile\Client;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use League\Flysystem\FileAttributes;
use Kapersoft\FlysystemSharefile\Util;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use Kapersoft\ShareFile\Exceptions\BadRequest;
use Kapersoft\FlysystemSharefile\SharefileAdapter;

/**
 * Offline Flysystem ShareFile Adapter tests.
 *
 * @author   Jan Willem Kaper <kapersoft@gmail.com>
 * @license  MIT (see License.txt)
 *
 * @link     http://github.com/kapersoft/flysystem-sharefile
 */
class SharefileAdapterTest extends TestCase
{

    use ProphecyTrait;

    /**
     * ShareFile client.
     *
     * @var \Kapersoft\ShareFile\Client|\Prophecy\Prophecy\ObjectProphecy
     */
    protected $client;

    /**
     * ShareFile Adapter.
     *
     * @var \Kapersoft\FlysystemSharefile\\SharefileAdapter
     */
    protected $adapter;

    /**
     * Virtual FS root.
     *
     * @var \org\bovigo\vfs\vfsStream
     * */
    protected $vfsRoot;

    /**
     * Folder prefix.
     *
     * @var  string
     */
    protected $prefix;

    /**
     * Setup tests.
     */
    public function setUp(): void
    {
        $this->vfsRoot = vfsStream::setup('home');

        $this->client = $this->prophesize(Client::class);

        $this->prefix = '/phpunit';

        $this->adapter = new SharefileAdapter($this->client->reveal(), $this->prefix);
    }

    /**
     * Test for itCanGetAClient.
     *
     * @test
     */
    public function itCanGetAClient()
    {
        $this->assertInstanceOf(Client::class, $this->adapter->getClient());
    }

    /**
     * Test for itHasCallsToGetMetaData.
     *
     * @test
     *
     * @param string $method   Method used for testing
     * @param string $filename Filename used for testing
     *
     * @dataProvider  metadataProvider
     */
    public function itHasCallsToGetMetaData(string $method, string $filename)
    {
        $this->client->getItemByPath($this->applyPathPrefix($filename))
            ->willReturn($this->mockSharefileItem($filename));

        $result = $this->adapter->{$method}($filename);

        $expectedResult = $this->calculateExpectedMetadata($filename);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Test for itWillNotHoldMetadataAfterFailing.
     *
     * @test
     *
     * @param string $method   Method used for testing
     * @param string $filename Filename used for testing
     *
     * @dataProvider  metadataProvider
     */
    public function itWillNotHoldMetadataAfterFailing(string $method, string $filename)
    {
        $this->client->getItemByPath($this->applyPathPrefix($filename))->willThrow(
            new BadRequest(new Response(404))
        );

        $result = $this->adapter->{$method}($filename);

        $this->assertFalse($result);
    }

    /**
     * Test for itCanRead.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanRead(string $filename)
    {
        $contents = $this->faker()->text;

        $this->client->getItemByPath($this->applyPathPrefix($filename))->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => '1',
                'Parent' => ['Id' => 1],
            ])
        );

        $this->client->getItemById(1)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Info' => ['CanDownload'   => 1],
        ]);

        $this->client->getItemContents(Argument::any())->willReturn($contents);

        $expectedResult = $this->calculateExpectedMetadata($filename, [
            'contents' => $contents,
         ]);
        $result = $this->adapter->read($filename);
        $this->assertsame($expectedResult['contents'], $result);
    }

    /**
     * Test for itCanReadstream.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanReadstream(string $filename)
    {
        $this->client->getItemByPath($this->applyPathPrefix($filename))->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => '2',
                'Parent' => ['Id' => 1],
            ])
        );

        $this->client->getItemById(1)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Info' => ['CanDownload'   => 1],
        ]);

        $contents = $this->faker()->text;
        $mockFile = $this->createMockFile($filename, $contents);

        $this->client->getItemDownloadUrl(Argument::any())->willReturn(['DownloadUrl' => $mockFile->url()]);

        $result = $this->adapter->readStream($filename);

        $this->assertIsResource($result);
        $this->assertSame($contents, stream_get_contents($result));
    }

    /**
     * Test for itCanListContents.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanListContents(string $filename)
    {
        $client = $this->client;
        $directory = substr($filename, 0, -4);

        $client->getItemByPath($this->applyPathPrefix($directory))->willReturn(
            $this->mockSharefileItem('SubFolder', [
                'odata.type'   => 'ShareFile.Api.Models.Folder',
                'Id' => '1',
            ])
        );

        $sharefileItemFolder = $this->mockSharefileItem($directory.'/folder', [
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => '2',
        ]);
        $sharefileItemFile = $this->mockSharefileItem($directory.'/folder/file.txt', [
            'odata.type'   => 'ShareFile.Api.Models.File',
            'Id' => '3',
        ]);
        $client->getItemById(1, true)->will(function () use ($client, $sharefileItemFolder, $sharefileItemFile) {
            $client->getItemById(2, true)->willReturn([
                'Children' => [
                        $sharefileItemFile,
                ],
            ]);

            return [
                'Children' => [
                    $sharefileItemFolder,
                ],
            ];
        });

        $result = $this->adapter->listContents($directory, true);

        $expectedResult = [
            $this->getExpectedStorageAttributes(
                $this->calculateExpectedMetadata($directory.'/folder', [
                    'mimetype' => 'inode/directory',
                    'type' => 'dir',
                ])
            ),
            $this->getExpectedStorageAttributes(
                $this->calculateExpectedMetadata($directory.'/folder/file.txt')
            ),
        ];

        $this->assertEquals($expectedResult, $result);
    }

    /**
     * Test for itCanWritePutAndUpdate.
     *
     * @test
     *
     * @param string $method   Method used for testing
     * @param string $filename Filename used for testing
     *
     * @dataProvider  updateProvider
     */
    public function itCanWrite(string $method, string $filename)
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $dirname = Util::dirname($filenamePrefix);
        $contents = $this->faker()->text;
        $client = $this->client;

        $sharefileItem = $this->mockSharefileItem($filename);
        $client->getItemByPath($dirname)->will(function () use ($client, $filename, $filenamePrefix, $sharefileItem) {
            $client->getItemByPath($filenamePrefix)->willReturn(
                $sharefileItem
            );

            return [
                'odata.type'   => 'ShareFile.Api.Models.Folder',
                'Id' => 1,
                'Info' => ['CanUpload'   => 1],
            ];
        });

        $this->client->uploadFileStreamed(Argument::any(), 1, basename($filename), false, true)->willReturn('');

        $result = $this->adapter->{$method}($filename, $contents);

        $expectedResult = $this->calculateExpectedMetadata($filename, [
            'contents' => $contents,
        ]);

        $this->assertSame(null, $result);
    }

    /**
     * Test for itCanWritestreamUpdatestream.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanWritestream(string $filename)
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $dirname = Util::dirname($filenamePrefix);
        $contents = $this->faker()->text;
        $client = $this->client;

        $sharefileItem = $this->mockSharefileItem($filename);
        $client->getItemByPath($dirname)->will(function () use ($client, $filename, $filenamePrefix, $sharefileItem) {
            $client->getItemByPath($filenamePrefix)->willReturn(
                $sharefileItem
            );

            return [
                'odata.type'   => 'ShareFile.Api.Models.Folder',
                'Id' => 1,
                'Info' => ['CanUpload'   => 1],
            ];
        });

        $this->client->uploadFileStreamed(Argument::any(), 1, basename($filename), false, true)->willReturn('');

        $resource = tmpfile();
        fseek($resource, 0);
        fwrite($resource, $contents);

        $this->assertNull($this->adapter->writeStream($filename, $resource, new Config([])));
    }

    /**
     * Test for itCanMoveAFile.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanMoveAFile(string $filename)
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $newPath = '/targetfolder/new name of '.basename($filename);
        $newPathPrefix = $this->applyPathPrefix($newPath);
        $newPathParent = Util::dirname($newPathPrefix);

        $this->client->getItemByPath($newPathParent)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanUpload'   => 1],
        ]);
        $this->client->getItemByPath($filenamePrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );
        $this->client->getItemByPath($newPathPrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );

        $data = [
            'FileName' =>  basename($newPath),
            'Name' =>  basename($newPath),
            'Parent' =>  [
                'Id' => 1,
            ],
        ];
        $this->client->updateItem(2, $data)->willReturn([]);

        $result = $this->adapter->rename($filename, $newPath);

        $this->assertTrue($result);
    }

    /**
     * Test for itWillReturnFalseWhenAMoveHasFailed.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itWillReturnFalseWhenAMoveHasFailed(string $filename)
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $newPath = '/targetfolder/new name of '.basename($filename);
        $newPathPrefix = $this->applyPathPrefix($newPath);
        $newPathParent = Util::dirname($newPathPrefix);

        $this->client->getItemByPath($newPathParent)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanUpload'   => 1],
        ]);
        $this->client->getItemByPath($filenamePrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );
        $this->client->getItemByPath($newPathPrefix)->willThrow(
            new BadRequest(new Response(404))
        );

        $data = [
            'FileName' =>  basename($newPath),
            'Name' =>  basename($newPath),
            'Parent' =>  [
                'Id' => 1,
            ],
        ];
        $this->client->updateItem(2, $data)->willReturn([]);

        $result = $this->adapter->rename($filename, $newPath);

        $this->assertFalse($result);
    }

    /**
     * Test for itCanCopyAFileToANewFolder.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanCopyAFileToANewFolder(string $filename)
    {
        $filename = basename($filename);
        $filenamePrefix = $this->applyPathPrefix($filename);
        $newPath = 'targetfolder/'.$filename;
        $newPathPrefix = $this->applyPathPrefix($newPath);
        $newPathParent = Util::dirname($newPathPrefix);

        $this->client->getItemByPath($newPathParent)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanUpload'   => 1],
        ]);

        $this->client->getItemByPath($filenamePrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );

        $this->client->copyItem(1, 2, true)->willReturn([]);

        $this->client->getItemByPath($newPathPrefix)->willReturn(
            $this->mockSharefileItem($newPath)
        );

        $result = $this->adapter->copy($filename, $newPath);

        $this->assertNull($result);
    }

    /**
     * Test for itCanCopyAFileToSameFolder.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanCopyAFileToSameFolder(string $filename)
    {
        $filename = basename($filename);
        $filenamePrefix = $this->applyPathPrefix($filename);
        $newPath = 'copy of '.$filename;
        $newPathPrefix = $this->applyPathPrefix($newPath);
        $newPathParent = Util::dirname($newPathPrefix);

        $this->client->getItemByPath($newPathParent)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanUpload'   => 1],
        ]);

        $this->client->getItemByPath($filenamePrefix)->willReturn(
            $this->mockSharefileItem($filename, [
                'Id' => 2,
            ])
        );

        $this->client->getItemContents(2)->willReturn('foo');

        $this->client->uploadFileStreamed(Argument::any(), 1, $newPath, false, true)->willReturn('');

        $this->client->getItemByPath($newPathPrefix)->willReturn(
            $this->mockSharefileItem($newPath)
        );

        $this->assertNull($this->adapter->copy($filename, $newPath));
    }

    /**
     * Test for itCanDeleteStuff.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanDeleteStuff(string $filename)
    {
        $filenamePrefix = $this->applyPathPrefix($filename);
        $dirname = Util::dirname($filename);
        $dirnamePrefix = $this->applyPathPrefix($dirname);

        $client = $this->client;
        $client->getItemByPath($filenamePrefix)->will(function () use ($client, $filenamePrefix) {
            $client->getItemByPath($filenamePrefix)->willThrow(
                new BadRequest(new Response(404))
            );

            return [
                'odata.type'   => 'ShareFile.Api.Models.File',
                'Id' => '2',
                'Parent' => ['Id' => 1],
            ];
        });

        $client->getItemByPath($dirnamePrefix)->will(function () use ($client, $dirnamePrefix) {
            $client->getItemByPath($dirnamePrefix)->willThrow(
                new BadRequest(new Response(404))
            );

            return [
                'odata.type'   => 'ShareFile.Api.Models.File',
                'Id' => '2',
                'Parent' => ['Id' => 1],
            ];
        });

        $this->client->getItemById(1)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Info' => ['CanDeleteChildItems'   => 1],
        ]);

        $this->client->deleteItem(2)->willReturn('');

        $this->assertNull($this->adapter->delete($filename));
        $this->assertNull($this->adapter->deleteDirectory($dirname));
    }

    /**
     * Test for itCanCreateADirectory.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itCanCreateADirectory(string $filename)
    {
        $path = substr($filename, 0, -4);
        $directory = basename($path);
        $pathPrefix = $this->applyPathPrefix($path);
        $pathParentPrefix = $this->applyPathPrefix(Util::dirname($path));

        $this->client->getItemByPath($pathParentPrefix)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => ['CanAddFolder'   => 1],
        ]);

        $this->client->createFolder(1, $directory, $directory, true)->willReturn([]);

        $this->client->getItemByPath($pathPrefix)->willReturn(
            $this->mockSharefileItem($path)
        );

        $result = $this->adapter->createDir($path);

        $expectedResult = $this->calculateExpectedMetadata($path);

        $this->assertSame($result, $expectedResult);
    }

    /**
     * Test for itReturnsFalseWhenParentFolderIsNotFound.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itThrowsExceptionWhenParentFolderIsNotFound(string $filename)
    {
        $pathParentPrefix = $this->applyPathPrefix(Util::dirname($filename));

        $this->client->getItemByPath($pathParentPrefix)->willThrow(new BadRequest(new Response(404)));

        $this->expectException(UnableToWriteFile::class);
        $this->adapter->write($filename, Argument::any(), new Config());

        $this->expectException(UnableToCopyFile::class);
        $this->adapter->copy(Argument::any(), $filename);

        $this->expectException(UnableToCreateDirectory::class);
        $this->adapter->createDirectory($filename, new Config());
    }

    /**
     * Test for itReturnsFalseWhenParentFolderHasNoRights.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itThrowsExceptionWhenParentFolderHasNoRights(string $filename)
    {
        $pathParentPrefix = $this->applyPathPrefix(Util::dirname($filename));

        $this->client->getItemByPath($pathParentPrefix)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => [
                'CanAddFolder' => 0,
                'CanAddNode' => 0,
                'CanView' => 0,
                'CanDownload' => 0,
                'CanUpload' => 0,
                'CanSend' => 0,
                'CanDeleteCurrentItem' => 0,
                'CanDeleteChildItems' => 0,
                'CanManagePermissions' => 0,
                'CanCreateOfficeDocuments' => 0,
            ],
        ]);

        $this->expectException(UnableToWriteFile::class);
        $this->expectExceptionMessage($filename);
        $this->adapter->write($filename, Argument::any(), new Config());

        $this->expectException(UnableToCopyFile::class);
        $this->expectExceptionMessage("Unable to copy file from * to $filename");
        $this->adapter->copy(Argument::any(), $filename);

        $this->expectException(UnableToCreateDirectory::class);
        $this->expectExceptionMessage("Unable to create a directory at $filename. You do not have permission to create this directory.");
        $this->adapter->createDirectory($filename, new Config());

    }

    /**
     * Test for itReturnsFalseWhenParentFolderHasAccessControl.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itReturnsFalseWhenParentFolderHasAccessControl(string $filename)
    {
        $pathParentPrefix = $this->applyPathPrefix(Util::dirname($filename));

        $this->client->getItemByPath($pathParentPrefix)->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
        ]);

        $this->expectException(UnableToWriteFile::class);
        $this->expectExceptionMessage($filename);
        $this->adapter->write($filename, Argument::any(), new Config());

        $this->expectException(UnableToCopyFile::class);
        $this->expectExceptionMessage("Unable to copy file from * to $filename");
        $this->adapter->copy(Argument::any(), $filename);

        $this->expectException(UnableToCreateDirectory::class);
        $this->expectExceptionMessage("Unable to create a directory at $filename. You do not have permission to create this directory.");
        $this->adapter->createDirectory($filename, new Config());
    }

    /**
     * Test for itReturnsFalseWhenItemIsNotFound.
     *
     * @test
     *
     * @param string $filename Filename used for testing
     *
     * @dataProvider  filenameProvider
     */
    public function itThrowsExceptionWhenItemIsNotFound(string $filename)
    {
        $filenamePrefix = $this->applyPathPrefix($filename);

        $this->client->getItemByPath(Argument::any())->willReturn([
            'odata.type'   => 'ShareFile.Api.Models.Folder',
            'Id' => 1,
            'Info' => [
                'CanAddFolder' => 1,
                'CanAddNode' => 1,
                'CanView' => 1,
                'CanDownload' => 1,
                'CanUpload' => 1,
                'CanSend' => 1,
                'CanDeleteCurrentItem' => 1,
                'CanDeleteChildItems' => 1,
                'CanManagePermissions' => 1,
                'CanCreateOfficeDocuments' => 1,
            ],
        ]);

        $this->client->getItemByPath($filenamePrefix)->willThrow(new BadRequest(new Response(404)));

        $this->expectException(UnableToReadFile::class);
        $this->adapter->read($filename, Argument::any());

        $this->assertEmpty($this->adapter->listContents($filename, Argument::any()));

        $this->expectException(UnableToCopyFile::class);
        $this->adapter->copy($filename, Argument::any());

        $this->expectException(UnableToDeleteFile::class);
        $this->adapter->delete($filename);

        $this->expectException(UnableToDeleteDirectory::class);
        $this->adapter->deleteDirectory($filename);
    }

    /**
     * Provider for filenames.
     *
     * @return array
     */
    public function filenameProvider(): array
    {
        return array_map(function (string $filename) {
            return [$filename];
        }, $this->filenames());
    }

    /**
     * Provider for update methods and filenames.
     *
     * @return array
     */
    public function updateProvider():array
    {
        $provider = [];
        foreach ($this->filenames() as $filename) {
            $provider[] = ['write', $filename];

            // removed from FlysystemAdapter interface
            // $provider[] = ['update', $filename];
            // $provider[] = ['put', $filename];
        }

        return $provider;
    }

    /**
     * Provider for metadata methods and filenames.
     *
     * @return array
     */
    public function metadataProvider(): array
    {
        $provider = [];
        foreach ($this->filenames() as $filename) {
            $provider[] = ['getMetadata', $filename];
            $provider[] = ['getTimestamp', $filename];
            $provider[] = ['getSize', $filename];
            $provider[] = ['has', $filename];
        }

        return $provider;
    }

    /**
     * List of filenames.
     *
     * @return array
     */
    protected function filenames()
    {
        return [
            'test/test.txt',
            'тёст/тёст.txt',
            'test 1/test.txt',
            'test/test 1.txt',
            'test  1/test  2.txt',
            $this->faker()->word.'/'.$this->randomFileName(),
            $this->faker()->word.'/'.$this->randomFileName(),
            $this->faker()->word.'/'.$this->randomFileName(),
            $this->faker()->word.'/'.$this->randomFileName(),
            $this->faker()->word.'/'.$this->randomFileName(),
        ];
    }

    /**
     * Creates mock ShareFile item.
     *
     * @param   string $filename Filename
     * @param   array  $extra    Additional properties
     * @return  array
     */
    protected function mockSharefileItem(string $filename, array $extra = []):array
    {
        return array_merge(
            [
                'odata.type'   => 'ShareFile.Api.Models.File',
                'ClientModifiedDate' => '2017-09-04T21:48:44Z',
                'FileName' => basename($filename),
                'FileSizeBytes' => 1024,
            ],
            $extra
        );
    }

    /**
     * Calculates expected metadata.
     *
     * @param   string $filename Filename
     * @param   array  $extra    Additional properties
     * @return  array
     */
    protected function calculateExpectedMetadata(string $filename, array $extra = []):array
    {
        return array_merge(
            [
                'timestamp' => 1504561724,
                'path' => $filename,
                'mimetype' => Util::guessMimeType($filename, ''),
                'dirname' => pathinfo($filename, PATHINFO_DIRNAME),
                'extension' => pathinfo($filename, PATHINFO_EXTENSION),
                'filename' => pathinfo($filename, PATHINFO_FILENAME),
                'basename' => pathinfo($filename, PATHINFO_FILENAME),
                'type' => 'file',
                'size' => 1024,
                'contents' => false,
                'stream' => false,
            ],
            $extra
        );
    }

    /**
     * Map metadata to appropriate StorageAttribute class.
     *
     * @param $itemMetadata
     * @return DirectoryAttributes|FileAttributes
     */
    protected function getExpectedStorageAttributes($itemMetadata)
    {
        if ($itemMetadata['type'] == 'file') {
            return new FileAttributes(
                $itemMetadata['path'],
                $itemMetadata['size'],
                null,
                $itemMetadata['timestamp'],
                $itemMetadata['mimetype']
            );
        }

        return new DirectoryAttributes($itemMetadata['path'],null, $itemMetadata['timestamp']);
    }

    /**
     * Return Faker Generator.
     *
     * @return Generator
     */
    protected function faker():Generator
    {
        return Factory::create();
    }

    /**
     * Generate random filename.
     *
     * @return string
     */
    protected function randomFileName()
    {
        return $this->faker()->name.'.'.$this->faker()->fileExtension;
    }

    /**
     * Calculate full path with prefix.
     *
     * @param string $path Path
     *
     * @return string
     */
    protected function applyPathPrefix(string $path):string
    {
        return '/'.trim($this->prefix, '/').'/'.trim($path, '/');
    }

    /**
     * Create a mock file.
     *
     * @param string $filename Filename
     * @param mixed  $contents Contents (optional)
     *
     * @return \org\bovigo\vfs\vfsStreamFile
     */
    private function createMockFile(string $filename, $contents = '')
    {
        return vfsStream::newFile($filename)->at($this->vfsRoot)->withContent($contents);
    }
}
