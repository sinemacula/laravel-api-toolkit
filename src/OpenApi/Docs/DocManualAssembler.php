<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use Illuminate\Support\Facades\Config;

/**
 * Assembles the committed Markdown manual injected into info.description.
 *
 * Reads the section files (`*.md`) from the configured docs directory and
 * concatenates them in filename (sorted) order, separated by a blank line, so
 * the rendered documentation opens with the manual and then lists the
 * endpoints. The assembly is dumb by design: no manifest, no per-audience
 * selection, no merge logic. The directory holds toolkit-fixed templates,
 * developer-authored sections, and later auto-generated sections side by side,
 * ordered purely by their numeric filename prefixes. A missing or empty
 * directory yields an empty string, so the manual is opt-in and stays absent
 * until the templates are published.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DocManualAssembler
{
    /** The default docs directory, relative to the application resources. */
    private const string DEFAULT_DIRECTORY = 'api-docs';

    /**
     * Assemble the concatenated Markdown manual from the configured docs
     * directory, or an empty string when the directory is absent or empty.
     *
     * @return string
     */
    public function assemble(): string
    {
        $path = $this->docsPath();

        if (!is_dir($path)) {
            return '';
        }

        $files = glob(rtrim($path, '/') . '/*.md');

        if ($files === false) {
            return ''; // @codeCoverageIgnore
        }

        sort($files);

        return $this->concatenate($files);
    }

    /**
     * Concatenate the given files' trimmed contents in order, separated by a
     * blank line, skipping any file that reads empty or unreadable.
     *
     * @param  array<int, string>  $files
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
     * Read a file's trimmed contents, tolerating a file removed between the
     * glob and the read by returning null rather than aborting the assembly.
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
     * Resolve the docs directory, falling back to the application resources
     * directory when the config value is unset, so a published-then-edited
     * directory is picked up automatically.
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
