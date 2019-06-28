<?php
namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Exception\FileNotFound;
use Gaufrette\Exception\StorageFailure;
use Google\Cloud\Exception\NotFoundException;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;

/**
 * Google Cloud Storage adapter using the Google Cloud Client Library for PHP
 * http://googlecloudplatform.github.io/google-cloud-php/
 *
 * @package Gaufrette
 * @author  Lech Buszczynski <lecho@phatcat.eu>
 */
final class GoogleCloudStorage implements Adapter, MetadataSupporter, ListKeysAware
{
    /**
     * @var StorageClient
     */
    private $storageClient;

    /**
     * @var Bucket
     */
    private $bucket;
    private $options = [];
    private $metadata = [];

    /**
     * @param StorageClient    $service    Authenticated storage client class
     * @param string           $bucketName Name of the bucket
     * @param array            $options    Options are: "directory" and "acl" (see https://cloud.google.com/storage/docs/access-control/lists)
     */
    public function __construct(StorageClient $storageClient, string $bucketName, $options = [])
    {
        $this->storageClient = $storageClient;
        $this->initBucket($bucketName);
        $this->options = array_replace_recursive(
            [
                'directory' => '',
                'acl' => [],
            ],
            $options
        );
        $this->options['directory'] = rtrim($this->options['directory'], '/');
    }

    /**
     * Get adapter options
     *
     * @return  array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Set adapter options
     *
     * @param   array   $options
     */
    public function setOptions($options)
    {
        $this->options = array_replace($this->options, $options);
    }

    /**
     * @return Bucket
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        $object = $this->bucket->object($this->computePath($key));

        try {
            return $object->downloadAsString();
        } catch (\Exception $e) {
            if ($e instanceof NotFoundException) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('read', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $options = [
            'resumable' => true,
            'name' => $this->computePath($key),
        ];

        try {
            $object = $this->bucket->upload(
                $content,
                $options
            );

            $this->setAcl($object);
        } catch (\Exception $e) {
            throw StorageFailure::unexpectedFailure('write', ['key' => $key, 'content' => $content], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        return $this->bucket->object($this->computePath($key))->exists();
    }

    /**
     * {@inheritdoc}
     */
    public function isDirectory($key)
    {
        return $this->exists($this->computePath(rtrim($key, '/')) . '/');
    }

    /**
     * {@inheritdoc}
     */
    public function listKeys($prefix = null)
    {
        $keys = [];

        $filter = [
            'prefix' => $this->computePath($prefix),
        ];

        foreach ($this->bucket->objects($filter) as $e) {
            $keys[] = strlen($this->options['directory'])
                ? substr($e->name(), strlen($this->options['directory'] . '/'))
                : $e->name()
            ;
        }

        sort($keys);

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        return $this->listKeys();
    }

    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        $info = $this->bucket->object($this->computePath($key))->info();

        return strtotime($info['updated']);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        try {
            $this->bucket->object($this->computePath($key))->delete();
        } catch (\Exception $e) {
            if ($e instanceof NotFoundException) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('delete', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        $sourcePath = $this->computePath($sourceKey);
        $targetPath = $this->computePath($targetKey);

        try {
            $object = $this->bucket->object($sourcePath);
            $metadata = $this->getMetadata($sourceKey);

            $copy = $object->copy(
                $this->bucket,
                [
                    'name' => $targetPath,
                ]
            );

            $this->setAcl($copy);
            $this->setMetadata($targetKey, $metadata);

            $object->delete();
        } catch (\Exception $e) {
            if ($e instanceof NotFoundException) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('rename', ['sourceKey' => $sourceKey, 'targetKey' => $targetKey], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($key)
    {
        try {
            $infos = $this->bucket->object($this->computePath($key))->info();

            return $infos['metadata'] ?? [];
        } catch (\Exception $e) {
            if ($e instanceof NotFoundException) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('getMetadata', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata($key, $metadata)
    {
        try {
            $this->bucket->object($this->computePath($key))
                ->update(['metadata' => $metadata])
            ;
        } catch (\Exception $e) {
            if ($e instanceof NotFoundException) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('setMetadata', ['key' => $key], $e);
        }
    }

    private function computePath($key = null)
    {
        if (strlen($this->options['directory'])) {
            return $this->options['directory'] . '/' . $key;
        }

        return $key;
    }

    private function initBucket($bucketName)
    {
        $this->bucket = $this->storageClient->bucket($bucketName);

        if (!$this->bucket->exists()) {
            throw new StorageFailure(sprintf('Bucket %s does not exist.', $bucketName));
        }
    }

    /**
     * Set the ACLs received in the options (if any) to the given $object.
     *
     * @param StorageObject $object
     */
    private function setAcl(StorageObject $object)
    {
        if (!isset($this->options['acl']) || empty($this->options['acl'])) {
            return;
        }

        $acl = $object->acl();

        foreach ($this->options['acl'] as $key => $value) {
            $acl->add($key, $value);
        }
    }
}
