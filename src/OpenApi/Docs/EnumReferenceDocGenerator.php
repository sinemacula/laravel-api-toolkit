<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\Support\PureEnumName;

/**
 * Renders the referenced enums as an auto-generated Markdown section.
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

    /**
     * Create a new enum reference documentation generator.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Docs\ModuleSectionGrouper  $grouper
     * @return void
     */
    public function __construct(

        /** Groups the enums into the sections the reference renders. */
        private ModuleSectionGrouper $grouper,
    ) {}

    /**
     * Render the enum reference section as Markdown for the given enum classes.
     *
     * @param  list<class-string>  $enums
     * @return string
     */
    public function generate(array $enums): string
    {
        $sections = $this->grouper->group(
            $enums,
            static fn (string $enum): string => $enum,
            $this->compareByComponentName(...),
        );

        $lines = [self::BANNER, '', '# Enum Reference', '', self::INTRO];

        if ($this->grouper->isCombined($sections)) {

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
     *
     * @throws \LogicException
     */
    private function enumSection(string $enum, string $level): array
    {
        if (!enum_exists($enum)) {
            throw new \LogicException(sprintf('The referenced class [%s] is not an enum', $enum));
        }

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
            : PureEnumName::of($case->getValue());

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
