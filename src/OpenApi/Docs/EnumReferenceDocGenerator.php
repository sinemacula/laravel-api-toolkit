<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;

/**
 * Renders the referenced enums as an auto-generated Markdown section.
 *
 * Groups the referenced enums by the module they belong to, so a modular
 * application reads one module section per module beneath an optional shared
 * "Common" section, with each enum a subsection carrying a table of its cases
 * in declaration order. A flat application resolves every enum to no module,
 * collapsing to one subsection per enum with no module headings as the
 * degenerate case of the same grouping. Enums are ordered by component name and
 * the section opens with a machine-generated banner, so the output is stable
 * and byte-identical between runs. A backed enum lists each case's backing
 * value; a pure enum has no backing value, so its value cell shows a hyphen
 * placeholder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class EnumReferenceDocGenerator
{
    /** The banner marking the file as regenerated on every run. */
    private const string BANNER = '<!-- This file is auto-generated. Do not edit; regenerating the documentation overwrites it. -->';

    /** The fixed introduction printed beneath the heading. */
    private const string INTRO = 'The enumerations the API references, each listing its permitted cases.';

    /** The value cell shown for a case with no backing value. */
    private const string NO_VALUE = '-';

    /** The heading for enums that belong to no module. */
    private const string COMMON = 'Common';

    /**
     * Create a new enum reference documentation generator.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Contracts\ModuleResolver  $resolver
     * @return void
     */
    public function __construct(

        /** Resolves the module each enum belongs to. */
        private ModuleResolver $resolver,
    ) {}

    /**
     * Render the enum reference section as Markdown for the given enum classes.
     *
     * @param  list<class-string>  $enums
     * @return string
     */
    public function generate(array $enums): string
    {
        $sections = $this->sections($enums);
        $lines    = [self::BANNER, '', '# Enum Reference', '', self::INTRO];

        if ($this->isCombined($sections)) {

            foreach ($sections[0]['items'] ?? [] as $enum) {
                $lines = array_merge($lines, $this->enumSection($enum, '##'));
            }

            return implode("\n", $lines) . "\n";
        }

        foreach ($sections as $section) {
            $lines[] = '';
            $lines[] = '## ' . $section['heading'];

            foreach ($section['items'] as $enum) {
                $lines = array_merge($lines, $this->enumSection($enum, '###'));
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Group the enums into an ordered list of sections, the shared section
     * first followed by one section per module sorted by name, each enum list
     * sorted by component name.
     *
     * @param  list<class-string>  $enums
     * @return list<array{heading: string, items: list<class-string>}>
     */
    private function sections(array $enums): array
    {
        $common  = [];
        $modules = [];

        foreach ($enums as $enum) {

            $module = $this->resolver->resolve($enum);

            if ($module === null) {
                $common[] = $enum;
                continue;
            }

            $modules[$module->key] ??= ['name' => $module->name, 'items' => []];
            $modules[$module->key]['items'][] = $enum;
        }

        return $this->orderSections($common, $modules);
    }

    /**
     * Assemble the ordered section list, sorting the shared bucket and each
     * module's enums by component name and the modules by name.
     *
     * @param  list<class-string>  $common
     * @param  array<string, array{name: string, items: list<class-string>}>  $modules
     * @return list<array{heading: string, items: list<class-string>}>
     */
    private function orderSections(array $common, array $modules): array
    {
        usort($common, $this->compareByComponentName(...));
        uasort($modules, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        $sections = [];

        if ($common !== []) {
            $sections[] = ['heading' => self::COMMON, 'items' => $common];
        }

        foreach ($modules as $module) {

            $items = $module['items'];

            usort($items, $this->compareByComponentName(...));

            $sections[] = ['heading' => $module['name'], 'items' => $items];
        }

        return $sections;
    }

    /**
     * Determine whether the sections collapse to the flat per-enum output,
     * which holds when nothing is grouped under a module.
     *
     * @param  list<array{heading: string, items: list<class-string>}>  $sections
     * @return bool
     */
    private function isCombined(array $sections): bool
    {
        return $sections === []
            || (count($sections) === 1 && $sections[0]['heading'] === self::COMMON);
    }

    /**
     * Compare two enum classes by their component name.
     *
     * @param  class-string  $a
     * @param  class-string  $b
     * @return int
     */
    private function compareByComponentName(string $a, string $b): int
    {
        return SchemaComponentName::fromEnum($a) <=> SchemaComponentName::fromEnum($b);
    }

    /**
     * Render one enum as a subsection heading followed by its case table.
     *
     * @param  class-string  $enum
     * @param  string  $level
     * @return list<string>
     */
    private function enumSection(string $enum, string $level): array
    {
        $lines = [
            '',
            $level . ' ' . SchemaComponentName::fromEnum($enum),
            '',
            '| Name | Value |',
            '| --- | --- |',
        ];

        foreach ((new \ReflectionEnum($enum))->getCases() as $case) {
            $lines[] = $this->caseRow($case);
        }

        return $lines;
    }

    /**
     * Render one enum case as a table row.
     *
     * @param  \ReflectionEnumUnitCase  $case
     * @return string
     */
    private function caseRow(\ReflectionEnumUnitCase $case): string
    {
        $value = $case instanceof \ReflectionEnumBackedCase
            ? (string) $case->getBackingValue()
            : self::NO_VALUE;

        return sprintf('| %s | %s |', $this->escape($case->getName()), $this->escape($value));
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
