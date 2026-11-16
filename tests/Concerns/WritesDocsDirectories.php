<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use PHPUnit\Framework\Attributes\After;

/**
 * Writes temporary docs directories, removed after each test.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait WritesDocsDirectories
{
    /** @var list<string> The temporary docs directories to remove. */
    private array $docsDirectories = [];

    /**
     * Remove every temporary docs directory.
     *
     * @return void
     */
    #[After]
    protected function removeDocsDirectories(): void
    {
        foreach ($this->docsDirectories as $directory) {
            $this->removeDocsEntry($directory);
        }

        $this->docsDirectories = [];
    }

    /**
     * Write a temporary docs directory holding the files, keyed by relative
     * path; a path ending in a slash is created as an empty directory.
     *
     * @param  array<string, string>  $files
     * @return string
     */
    private function writeDocsDirectory(array $files = []): string
    {
        $directory = sys_get_temp_dir() . '/api-toolkit-docs-' . uniqid('', true);

        mkdir($directory);

        $this->docsDirectories[] = $directory;

        foreach ($files as $path => $contents) {

            $file   = $directory . '/' . $path;
            $parent = str_ends_with($path, '/') ? $file : dirname($file);

            if (!is_dir($parent)) {
                mkdir($parent, 0o777, true);
            }

            if ($parent === $file) {
                continue;
            }

            file_put_contents($file, $contents);
        }

        return $directory;
    }

    /**
     * Write a temporary docs directory and point the docs path at it.
     *
     * @param  array<string, string>  $files
     * @return string
     */
    private function useDocsDirectory(array $files = []): string
    {
        $directory = $this->writeDocsDirectory($files);

        config()->set('api-toolkit.openapi.docs_path', $directory);

        return $directory;
    }

    /**
     * Remove a file, or a directory and everything beneath it.
     *
     * @param  string  $entry
     * @return void
     */
    private function removeDocsEntry(string $entry): void
    {
        if (!is_dir($entry)) {
            @unlink($entry);

            return;
        }

        foreach (array_diff(scandir($entry) ?: [], ['.', '..']) as $child) {
            $this->removeDocsEntry($entry . '/' . $child);
        }

        @rmdir($entry);
    }
}
