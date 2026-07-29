<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;

/**
 * Renders the error catalogue as an auto-generated Markdown section.
 *
 * Emits a single table of every documented error code sorted ascending by code,
 * so the rendered output is stable and byte-identical between runs. The section
 * opens with a machine-generated banner marking it as regenerated data rather
 * than hand-edited prose, so a companion assembler can concatenate it into the
 * OpenAPI description alongside the fixed templates. All codes are listed
 * together with no per-module grouping.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ErrorCatalogueDocGenerator
{
    /** The banner marking the file as regenerated on every run. */
    private const string BANNER = '<!-- This file is auto-generated. Do not edit; regenerating the documentation overwrites it. -->';

    /** The fixed introduction printed beneath the heading. */
    private const string INTRO = 'Every error the API can return, keyed by its stable numeric code.';

    /**
     * Render the error catalogue section as Markdown for the given descriptors.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>  $descriptors
     * @return string
     */
    public function generate(array $descriptors): string
    {
        usort($descriptors, static fn (ErrorDescriptor $a, ErrorDescriptor $b): int => $a->code <=> $b->code);

        $lines = [
            self::BANNER,
            '',
            '# Error Catalogue',
            '',
            self::INTRO,
            '',
            '| Name | Code | HTTP | Description |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($descriptors as $descriptor) {
            $lines[] = $this->row($descriptor);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render one descriptor as a table row, falling back to a derived name when
     * the descriptor carries no title.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor  $descriptor
     * @return string
     */
    private function row(ErrorDescriptor $descriptor): string
    {
        $name = $descriptor->title ?? sprintf('Error %d', $descriptor->code);

        return sprintf(
            '| %s | %d | %d | %s |',
            $this->escape($name),
            $descriptor->code,
            $descriptor->httpStatus,
            $this->escape($descriptor->detail),
        );
    }

    /**
     * Escape a table cell so a pipe or line break cannot break the table.
     *
     * @param  string  $text
     * @return string
     */
    private function escape(string $text): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\|', ' ', ' ', ' '], $text);
    }
}
