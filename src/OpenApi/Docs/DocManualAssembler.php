<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use Illuminate\Support\Facades\Config;

/**
 * Assembles the committed Markdown manual injected into info.description.
 *
 * An audience's own files under `audiences/<name>` join, and by name replace,
 * the shared ones.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DocManualAssembler
{
    /** The subdirectory holding one directory of section files per audience. */
    public const string AUDIENCE_DIRECTORY = 'audiences';

    /** The default docs directory, relative to the application resources. */
    private const string DEFAULT_DIRECTORY = 'api-docs';

    /**
     * Assemble the concatenated Markdown manual for the given audience.
     *
     * @param  string|null  $audience
     * @return string
     */
    public function assemble(?string $audience = null): string
    {
        return $this->concatenate($this->sectionFiles($this->docsPath(), $audience));
    }

    /**
     * List the section files the audience reads, ordered by filename.
     *
     * @param  string  $path
     * @param  string|null  $audience
     * @return array<string, string>
     */
    private function sectionFiles(string $path, ?string $audience): array
    {
        $files = [];

        foreach ($this->markdownIn($path) as $file) {
            $files[basename($file)] = $file;
        }

        foreach ($this->markdownIn($this->audiencePath($path, $audience)) as $file) {
            $files[basename($file)] = $file;
        }

        ksort($files);

        return $files;
    }

    /**
     * Resolve the directory holding the given audience's own section files.
     *
     * @param  string  $path
     * @param  string|null  $audience
     * @return string|null
     */
    private function audiencePath(string $path, ?string $audience): ?string
    {
        if ($audience === null || $audience === '') {
            return null;
        }

        return rtrim($path, '/') . '/' . self::AUDIENCE_DIRECTORY . '/' . $audience;
    }

    /**
     * List the Markdown files directly within the given directory.
     *
     * @param  string|null  $directory
     * @return list<string>
     */
    private function markdownIn(?string $directory): array
    {
        if ($directory === null || !is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, '/') . '/*.md');

        if ($files === false) {
            return []; // @codeCoverageIgnore
        }

        return $files;
    }

    /**
     * Concatenate the non-empty files' trimmed contents, separated by a blank
     * line.
     *
     * @param  array<string, string>  $files
     * @return string
     */
    private function concatenate(array $files): string
    {
        $sections = [];

        foreach ($files as $file) {

            $contents = $this->read($file);

            if ($contents === null || $contents === '') {
                continue;
            }

            $sections[] = $contents;
        }

        return implode("\n\n", $sections);
    }

    /**
     * Read a file's trimmed contents, tolerating one removed since the glob.
     *
     * @param  string  $file
     * @return string|null
     */
    private function read(string $file): ?string
    {
        try {
            $contents = file_get_contents($file);
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown
            return null; // @codeCoverageIgnore
        }

        if ($contents === false) {
            return null; // @codeCoverageIgnore
        }

        $trimmed = trim($contents);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Resolve the docs directory, defaulting to the application resources.
     *
     * @return string
     */
    private function docsPath(): string
    {
        $configured = Config::get('api-toolkit.openapi.docs_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : resource_path(self::DEFAULT_DIRECTORY);
    }
}
