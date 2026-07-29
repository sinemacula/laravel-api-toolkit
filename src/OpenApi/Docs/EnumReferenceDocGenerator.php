<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;

/**
 * Renders the referenced enums as an auto-generated Markdown section.
 *
 * Emits one subsection per enum, its heading matching the OpenAPI component
 * name the enum documents as, followed by a table of every case in declaration
 * order. Enums are ordered by component name so the output is stable and
 * byte-identical between runs. The section opens with a machine-generated
 * banner marking it as regenerated data rather than hand-edited prose. A backed
 * enum lists each case's backing value; a pure enum has no backing value, so
 * its value cell shows a hyphen placeholder.
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

    /**
     * Render the enum reference section as Markdown for the given enum classes.
     *
     * @param  list<class-string>  $enums
     * @return string
     */
    public function generate(array $enums): string
    {
        usort($enums, static fn (string $a, string $b): int => SchemaComponentName::fromEnum($a) <=> SchemaComponentName::fromEnum($b));

        $lines = [
            self::BANNER,
            '',
            '# Enum Reference',
            '',
            self::INTRO,
        ];

        foreach ($enums as $enum) {
            $lines = array_merge($lines, $this->section($enum));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Render one enum as a subsection heading followed by its case table.
     *
     * @param  class-string  $enum
     * @return list<string>
     */
    private function section(string $enum): array
    {
        $lines = [
            '',
            '## ' . SchemaComponentName::fromEnum($enum),
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
