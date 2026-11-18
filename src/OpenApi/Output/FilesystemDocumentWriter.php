<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Output;

use Illuminate\Filesystem\Filesystem;
use SineMacula\ApiToolkit\OpenApi\Contracts\DocumentWriter;
use SineMacula\ApiToolkit\OpenApi\Exceptions\DocumentWriteException;

/**
 * Writes the serialized OpenAPI documents to disk.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FilesystemDocumentWriter implements DocumentWriter
{
    /** The suffix of the file a document is staged in beside its target */
    private const string STAGING_SUFFIX = '.tmp';

    /**
     * Create a new filesystem document writer.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     */
    public function __construct(

        /** The filesystem used to write the document to disk. */
        private Filesystem $files,
    ) {}

    /**
     * Persist every serialized document at its filesystem path, staging each
     * beside its target so none is replaced unless all could be staged.
     *
     * @param  array<string, string>  $documents
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\DocumentWriteException
     */
    #[\Override]
    public function write(array $documents): void
    {
        $staged = [];

        try {
            foreach ($documents as $path => $contents) {
                $staged[$path] = $path . self::STAGING_SUFFIX;

                $this->stage($path, $staged[$path], $contents);
            }

            foreach ($staged as $path => $staging) {

                if (!$this->files->move($staging, $path)) {
                    throw new DocumentWriteException(sprintf('Unable to write the OpenAPI document to [%s].', $path));
                }

                unset($staged[$path]);
            }
        } finally {
            $this->files->delete(array_values($staged));
        }
    }

    /**
     * Write the document to its staging file beside its target.
     *
     * @param  string  $path
     * @param  string  $staging
     * @param  string  $contents
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\DocumentWriteException
     */
    private function stage(string $path, string $staging, string $contents): void
    {
        $directory = dirname($path);

        if (!$this->files->isDirectory($directory) && !$this->files->makeDirectory($directory, 0o755, true, true)) {
            throw new DocumentWriteException(sprintf('Unable to create directory [%s] for the OpenAPI document.', $directory));
        }

        if ($this->files->put($staging, $contents) === false) {
            throw new DocumentWriteException(sprintf('Unable to write the OpenAPI document to [%s].', $path));
        }
    }
}
