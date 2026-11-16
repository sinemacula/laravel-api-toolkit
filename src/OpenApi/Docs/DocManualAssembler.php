<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

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
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\DocsDirectory  $directory
     */
    public function __construct(

        /** The docs directory the section files are read from. */
        private DocsDirectory $directory = new DocsDirectory,
    ) {}

    /**
     * Assemble the concatenated Markdown manual for the given audience.
     *
     * @param  string|null  $audience
     * @return string
     */
    public function assemble(?string $audience = null): string
    {
        return $this->concatenate($this->sectionFiles($audience));
    }

    /**
     * List the section files the audience reads, ordered by filename.
     *
     * @param  string|null  $audience
     * @return array<string, string>
     */
    private function sectionFiles(?string $audience): array
    {
        $files = [];

        foreach ($this->directory->markdownIn($this->directory->root()) as $file) {
            $files[basename($file)] = $file;
        }

        if ($audience !== null && $audience !== '') {
            foreach ($this->directory->markdownIn($this->directory->audienceRoot($audience)) as $file) {
                $files[basename($file)] = $file;
            }
        }

        ksort($files);

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

            $contents = $this->directory->read($file);

            if ($contents === '') {
                continue;
            }

            $sections[] = $contents;
        }

        return implode("\n\n", $sections);
    }
}
