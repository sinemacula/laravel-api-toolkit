<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;

/**
 * Renders the error catalogue as an auto-generated Markdown section.
 *
 * Groups the documented error codes by the module their owning exception
 * belongs to, so a modular application reads one section per module beneath an
 * optional shared "Common" section for codes that belong to no module. A flat
 * application resolves every code to no module, collapsing to the single
 * combined table with no module headings as the degenerate case of the same
 * grouping. Each table is sorted ascending by code and the section opens with a
 * machine-generated banner, so the rendered output is stable and byte-identical
 * between runs.
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

    /** The heading for codes that belong to no module. */
    private const string COMMON = 'Common';

    /**
     * Create a new error catalogue documentation generator.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver  $resolver
     * @return void
     */
    public function __construct(

        /** Resolves the module each descriptor's owning exception belongs to. */
        private ModuleResolver $resolver,
    ) {}

    /**
     * Render the error catalogue section as Markdown for the given descriptors.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>  $descriptors
     * @return string
     */
    public function generate(array $descriptors): string
    {
        $sections = $this->sections($descriptors);
        $lines    = [self::BANNER, '', '# Error Catalogue', '', self::INTRO];

        if ($this->isCombined($sections)) {
            $lines = array_merge($lines, [''], $this->table($sections[0]['items'] ?? []));

            return implode("\n", $lines) . "\n";
        }

        foreach ($sections as $section) {
            $lines[] = '';
            $lines[] = '## ' . $section['heading'];
            $lines[] = '';
            $lines   = array_merge($lines, $this->table($section['items']));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Group the descriptors into an ordered list of sections, the shared
     * section first followed by one section per module sorted by name.
     *
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>  $descriptors
     * @return list<array{heading: string, items: list<\SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>}>
     */
    private function sections(array $descriptors): array
    {
        $common  = [];
        $modules = [];

        foreach ($descriptors as $descriptor) {

            $module = $descriptor->source === null ? null : $this->resolver->resolve($descriptor->source);

            if ($module === null) {
                $common[] = $descriptor;
                continue;
            }

            $modules[$module->key] ??= ['name' => $module->name, 'items' => []];
            $modules[$module->key]['items'][] = $descriptor;
        }

        uasort($modules, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $this->orderSections($common, $modules);
    }

    /**
     * Assemble the ordered section list from the shared bucket and the modules.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>  $common
     * @param  array<string, array{name: string, items: list<\SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>}>  $modules
     * @return list<array{heading: string, items: list<\SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>}>
     */
    private function orderSections(array $common, array $modules): array
    {
        $sections = [];

        if ($common !== []) {
            $sections[] = ['heading' => self::COMMON, 'items' => $common];
        }

        foreach ($modules as $module) {
            $sections[] = ['heading' => $module['name'], 'items' => $module['items']];
        }

        return $sections;
    }

    /**
     * Determine whether the sections collapse to the single combined table,
     * which holds when nothing is grouped under a module.
     *
     * @param  list<array{heading: string, items: list<\SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>}>  $sections
     * @return bool
     */
    private function isCombined(array $sections): bool
    {
        return $sections === []
            || (count($sections) === 1 && $sections[0]['heading'] === self::COMMON);
    }

    /**
     * Render a table of descriptors, sorted ascending by code.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor>  $descriptors
     * @return list<string>
     */
    private function table(array $descriptors): array
    {
        usort($descriptors, static fn (ErrorDescriptor $a, ErrorDescriptor $b): int => $a->code <=> $b->code);

        $lines = [
            '| Name | Code | HTTP | Description |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($descriptors as $descriptor) {
            $lines[] = $this->row($descriptor);
        }

        return $lines;
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
