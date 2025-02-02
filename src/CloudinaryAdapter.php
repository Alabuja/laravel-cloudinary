<?php

namespace Unicodeveloper\Cloudinary;

use Cloudinary\Cloudinary;
use League\Flysystem\Config;
use League\Flysystem\Util\MimeType;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;


class CloudinaryAdapter implements AdapterInterface
{
    use NotSupportingVisibilityTrait;

    /** Cloudinary\Cloudinary */
    protected $cloudinary;


    /**
     * Constructor
     * Sets configuration for Cloudinary Api.
     * @param string $config Cloudinary configuration
     */
    public function __construct(string $config)
    {
        $this->cloudinary = new Cloudinary($config);
    }


    /**
     * Write a new file.
     * Create temporary stream with content.
     * Pass to writeStream.
     *
     * @param string $path
     * @param string $contents
     * @param Config $options   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $options)
    {
        fwrite(tmpfile(), $contents);

        return $this->writeStream($path, $tempFile, $options);
    }


    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $options   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $options)
    {
        $publicId = $options->has('public_id') ? $options->get('public_id') : $path;

        $resourceType = $options->has('resource_type') ? $options->get('resource_type') : 'auto';

        $fileExtension = pathinfo($publicId, PATHINFO_EXTENSION);

        $newPublicId = $fileExtension ? substr($publicId, 0, - (strlen($fileExtension) + 1)) : $publicId;

        $uploadOptions = [
            'public_id' => $newPublicId,
            'resource_type' => $resourceType
        ];

        $resourceMetadata = stream_get_meta_data($resource);

        $result = resolve(CloudinaryEngine::class)->upload($resourceMetadata['uri'], $uploadOptions);

        return $result;
    }


    /**
     * Update a file.
     * Cloudinary has no specific update method. Overwrite instead.
     *
     * @param string $path
     * @param string $contents
     * @param Config $options   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $options)
    {
        return $this->write($path, $contents, $options);
    }


    /**
     * Update a file using a stream.
     * Cloudinary has no specific update method. Overwrite instead.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $options   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $options)
    {
        return $this->writeStream($path, $resource, $options);
    }


    /**
     * Rename a file.
     * Paths without extensions.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $pathInfo = pathinfo($path);
        $newPathInfo = pathinfo($newpath);

        $remotePath = ($pathInfo['dirname'] != '.') ? pathInfo['dirname'] . '/' . $pathInfo['filename'] : $pathInfo['filename'];

        $remoteNewPath = ($pathInfo['dirname'] != '.') ? $newPathInfo['dirname'] . '/' . $newPathInfo['filename'] : $newPathInfo['filename'];

        $result = $this->uploadApi()->rename($remotePath, $remoteNewPath);

        return $result['public_id'] == $newPathInfo['filename'];
    }

    /**
     * Copy a file.
     * Copy content from existing url.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $result = $this->uploadApi()->upload($path, ['public_id' => $newpath]);

        return is_array($result) ? $result['public_id'] == $newpath : false;
    }
    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $result = $this->uploadApi()->destroy($path);

        return is_array($result) ? $result['result'] == 'ok' : false;
    }

    /**
     * Delete a directory.
     * Delete Files using directory as a prefix.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $this->adminApi()->deleteResourcesByPrefix($dirname);

        return true;
    }
    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $options
     *
     * @return bool
     */
    public function createDir($dirname, Config $options)
    {
        $this->adminApi()->createFolder($dirname);

        return true;
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        return file_exists($path);
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $resource = (array) $this->adminApi()->resource($path);
        $contents = file_get_contents($resource['secure_url']);

        return compact('contents', 'path');
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $resource = (array) $this->adminApi()->resource($path);

        $stream = fopen($resource['secure_url'], 'r');

        return compact('stream', 'path');
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $hasRecursive = false)
    {
        $resources = [];

        // get resources array
        $response = null;
        do {
            $response = (array) $this->adminApi()->resources([
                'type' => 'upload',
                'prefix' => $directory,
                'max_results' => 500,
                'next_cursor' => isset($response['next_cursor']) ? $response['next_cursor'] : null,
            ]);
            $resources = array_merge($resources, $response['resources']);
        } while (array_key_exists('next_cursor', $response));

        // parse resourses
        foreach ($resources as $i => $resource) {
            $resources[$i] = $this->prepareResourceMetadata($resource);
        }
        return $resources;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
       return $this->prepareResourceMetadata($this->getResource($path));
    }

    /**
     * Get Resource data
     * @param  string $path
     * @return array
     */
    public function getResource($path)
    {
        return (array) $this->adminApi()->resource($path);
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->prepareSize($this->getResource($path));
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->prepareMimetype($this->getResource($path));
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->prepareTimestamp($this->getResource($path));
    }

    /**
     * Prepare apropriate metadata for resource metadata given from cloudinary.
     * @param  array $resource
     * @return array
     */
    protected function prepareResourceMetadata($resource)
    {
        $resource['type'] = 'file';
        $resource['path'] = $resource['public_id'];
        $resource = array_merge($resource, $this->prepareSize($resource));
        $resource = array_merge($resource, $this->prepareTimestamp($resource));
        $resource = array_merge($resource, $this->prepareMimetype($resource));
        return $resource;
    }

    /**
     * prepare mimetype response
     * @param  array $resource
     * @return array
     */
    protected function prepareMimetype($resource)
    {
        $mimetype = $resource['resource_type'];
        return compact('mimetype');
    }

    /**
     * prepare timestpamp response
     * @param  array $resource
     * @return array
     */
    protected function prepareTimestamp($resource)
    {
        $timestamp = strtotime($resource['created_at']);
        return compact('timestamp');
    }

    /**
     * prepare size response
     * @param array $resource
     * @return array
     */
    protected function prepareSize($resource)
    {
        $size = $resource['bytes'];
        return compact('size');
    }

    /**
    * Expose the Cloudinary v2 Upload Functionality
    *
    */
    protected function uploadApi()
    {
        return $this->cloudinary->uploadApi();
    }

    /**
    * Expose the Cloudinary v2 Upload Functionality
    *
    */
    protected function adminApi()
    {
        return $this->cloudinary->adminApi();
    }
}