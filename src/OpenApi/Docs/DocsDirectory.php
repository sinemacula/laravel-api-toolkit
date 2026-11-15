<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use Illuminate\Support\Facades\Config;

/**
 * Locates and reads the Markdown docs directory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DocsDirectory
{
    /** The subdirectory holding one directory of files per audience */
    public const string AUDIENCE_DIRECTORY = 'audiences';

    /** The default docs directory, relative to the application resources */
    private const string DEFAULT_DIRECTORY = 'api-docs';

    /**
     * Resolve the docs directory, defaulting to the application resources.
     *
     * @return string
     */
    public function root(): string
    {
        $configured = Config::get('api-toolkit.openapi.docs_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : resource_path(self::DEFAULT_DIRECTORY);
    }

    /**
     * Resolve the directory holding the given audience's own files.
     *
     * @param  string  $audience
     * @return string
     */
    public function audienceRoot(string $audience): string
    {
        return $this->audiencesRoot() . '/' . $audience;
    }

    /**
     * List the Markdown files directly within the directory, in filename order.
     *
     * @param  string  $directory
     * @return list<string>
     */
    public function markdownIn(string $directory): array
    {
        return glob(rtrim($directory, '/') . '/*.md') ?: [];
    }

    /**
     * List the names of the audience directories, in name order.
     *
     * @return list<string>
     */
    public function audienceDirectories(): array
    {
        $directories = glob($this->audiencesRoot() . '/*', GLOB_ONLYDIR) ?: [];

        return array_map(basename(...), $directories);
    }

    /**
     * Read a file's trimmed contents, or an empty string when it cannot be
     * read.
     *
     * @param  string  $file
     * @return string
     */
    public function read(string $file): string
    {
        try {
            $contents = file_get_contents($file);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            $contents = false; // @codeCoverageIgnore
        }

        return trim((string) $contents);
    }

    /**
     * Resolve the directory holding every audience directory.
     *
     * @return string
     */
    private function audiencesRoot(): string
    {
        return rtrim($this->root(), '/') . '/' . self::AUDIENCE_DIRECTORY;
    }
}
