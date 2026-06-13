<?php

namespace SineMacula\ApiToolkit\OpenApi\Output;

use Illuminate\Filesystem\Filesystem;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;

/**
 * Filesystem adapter for the DocumentWriter output port.
 *
 * Writes the serialized OpenAPI document to disk, creating the target
 * directory tree when it does not yet exist. This is the only write of the
 * entire exporter feature; failures surface as a RuntimeException so the
 * command can report them rather than silently emitting nothing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class FilesystemDocumentWriter implements DocumentWriter
{
    /**
     * Create a new filesystem document writer.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     */
    public function __construct(
        private readonly Filesystem $files,
    ) {}

    /**
     * Persist the serialized document to the given filesystem path.
     *
     * @param  string  $path
     * @param  string  $contents
     * @return void
     *
     * @throws \RuntimeException
     */
    #[\Override]
    public function write(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!$this->files->isDirectory($directory) && !$this->files->makeDirectory($directory, 0o755, true, true)) {
            throw new \RuntimeException(sprintf('Unable to create directory [%s] for the OpenAPI document.', $directory));
        }

        if ($this->files->put($path, $contents) === false) {
            throw new \RuntimeException(sprintf('Unable to write the OpenAPI document to [%s].', $path));
        }
    }
}
