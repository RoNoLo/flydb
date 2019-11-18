<?php

namespace RoNoLo\JsonStorage;

use League\Flysystem\{AdapterInterface, FileNotFoundException, Filesystem};
use RoNoLo\JsonStorage\Exception\{DocumentNotFoundException, DocumentNotStoredException};
use RoNoLo\JsonStorage\Store\DocumentIterator;

/**
 * Store
 *
 * Analageous to a table in a traditional RDBMS, a store is a collection where documents live.
 */
class Store
{
    /** @var Filesystem */
    protected $flysystem;

    /**
     * Constructor
     *
     * @param AdapterInterface $adapter
     */
    public function __construct(AdapterInterface $adapter)
    {
        $this->flysystem = new Filesystem($adapter);
    }

    /** @inheritDoc */
    public function has(string $id): bool
    {
        $path = $this->getPathForDocument($id);

        return $this->flysystem->has($path);
    }

    /** @inheritDoc */
    public function putMany(array $documents): array
    {
        // This will force an array as root
        $documents = json_decode(json_encode($documents));

        if (!is_array($documents)) {
            throw new DocumentNotStoredException("Your data was not an array of objects. (To store objects use ->put() instead.)");
        }

        $ids = [];
        foreach ($documents as $document) {
            $ids[] = $this->put($document);
        }

        return $ids;
    }

    /** @inheritDoc */
    public function put($document): string
    {
        // This will force an stdClass object as root
        $document = json_decode(json_encode($document));

        if (!is_object($document)) {
            throw new DocumentNotStoredException("Your data was not an single object. (Maybe an array, you may use ->putMany() instead.)");
        }

        if (!isset($document->__id)) {
            $id = $this->generateId();
            $document->__id = $id;
        } else {
            $id = $document->__id;
        }

        $path = $this->getPathForDocument($id);
        $json = json_encode($document, defined('STORE_JSON_OPTIONS') ? intval(STORE_JSON_OPTIONS) : 0);

        if (!$this->flysystem->put($path, $json)) {
            throw new DocumentNotStoredException(
                sprintf(
                    "The document could not be stored. Writing to flysystem-adapter `%s` failed.",
                    get_class($this->flysystem->getAdapter())
                )
            );
        }

        return $id;
    }

    /** @inheritDoc */
    public function read(string $id, $assoc = false)
    {
        $path = $this->getPathForDocument($id);

        try {
            $json = $this->flysystem->read($path);

            if (is_null($assoc)) {
                return $json;
            }

            $document = json_decode($json, !!$assoc);

            return $document;
        }
        catch (FileNotFoundException $e) {
            throw new DocumentNotFoundException(sprintf("Document with id `%s` not found.", $id), 0, $e);
        }
    }

    /** @inheritDoc */
    public function readMany(array $ids, $assoc = false, $check = true)
    {
        if (!$check) {
            return new DocumentIterator($this, $ids, [], $assoc);
        }

        $existIds = [];
        foreach ($ids as $id) {
            if ($this->has($id)) {
                $existIds[] = $id;
            }
        }

        return new DocumentIterator($this, $existIds, [], $assoc);
    }

    /** @inheritDoc */
    public function remove(string $id)
    {
        try {
            return $this->flysystem->delete($this->getPathForDocument($id));
        } catch (FileNotFoundException $e) {
            return true; // Fail silently, because the document is not there anyway.
        }
    }

    /** @inheritDoc */
    public function removeMany(array $ids)
    {
        foreach ($ids as $id) {
            $this->remove($id);
        }
    }

    /** @inheritDoc */
    public function truncate()
    {
        $contents = $this->flysystem->listContents('');

        foreach ($contents as $content) {
            if ($content['type'] == 'dir') {
                $this->flysystem->deleteDir($content['path']);
            }
        }
    }

    /** @inheritDoc */
    public function generateAllDocuments(string $storeName = null): \Generator
    {
        $contents = $this->flysystem->listContents('', true);

        foreach ($contents as $content) {
            if ($content['type'] != 'file') {
                continue;
            }
            if ($content['extension'] != 'json') {
                continue;
            }

            yield $this->flysystem->read($content['path']);
        }
    }

    /**
     * Get the filesystem path for a document based on it's ID.
     *
     * @param string $id The ID of the document.
     *
     * @return string The full filesystem path of the document.
     */
    protected function getPathForDocument(string $id): string
    {
        return substr($id, 0, 1) . '/' . substr($id, 0, 2) . '/' . $id . '.json';
    }

    /**
     * Generates a random, unique ID for a document.
     *
     * @return string The generated ID.
     * @throws DocumentNotStoredException
     */
    protected function generateId()
    {
        $breaker = 10;
        while ($breaker) {
            $id = strrev(str_replace('.', '', uniqid('', true)));
            $path = $this->getPathForDocument($id);

            if (!$this->flysystem->has($path)) {
                return $id;
            }

            $breaker--;
        }

        throw new DocumentNotStoredException("It was not possible to generate a unique ID for the document (tried 10 times).");
    }
}
