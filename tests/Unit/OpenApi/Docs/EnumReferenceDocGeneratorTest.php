<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\EnumReferenceDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Docs\Module;
use Tests\Fixtures\Enums\Alternate\Tier as AlternateTier;
use Tests\Fixtures\Enums\DocumentFormat;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\OpenApi\MappedModuleResolver;
use Tests\TestCase;

/**
 * Tests for the enum reference documentation generator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(EnumReferenceDocGenerator::class)]
final class EnumReferenceDocGeneratorTest extends TestCase
{
    /**
     * Test that the generator emits the banner and heading.
     *
     * @return void
     */
    public function testEmitsBannerAndHeading(): void
    {
        $markdown = $this->combinedGenerator()->generate([UserStatus::class]);

        self::assertStringStartsWith('<!-- This file is auto-generated.', $markdown);
        self::assertStringContainsString("\n# Enum Reference\n", $markdown);
        self::assertStringEndsWith("\n", $markdown);
    }

    /**
     * Test that a backed enum emits a subsection with its component-name
     * heading and a well-formed row per case carrying the backing value.
     *
     * @return void
     */
    public function testBackedEnumEmitsSubsectionWithBackingValues(): void
    {
        $markdown = $this->combinedGenerator()->generate([UserStatus::class]);

        self::assertStringContainsString("## UserStatus\n", $markdown);
        self::assertStringContainsString("| Name | Value |\n| --- | --- |\n", $markdown);
        self::assertStringContainsString('| ACTIVE | active |', $markdown);
        self::assertStringContainsString('| BANNED | banned |', $markdown);
    }

    /**
     * Test that an int-backed enum renders its integer backing values.
     *
     * @return void
     */
    public function testIntBackedEnumRendersIntegerValues(): void
    {
        $markdown = $this->combinedGenerator()->generate([UserLevel::class]);

        self::assertStringContainsString('| BRONZE | 1 |', $markdown);
        self::assertStringContainsString('| GOLD | 3 |', $markdown);
    }

    /**
     * Test that a pure enum renders a hyphen placeholder in the value cell.
     *
     * @return void
     */
    public function testPureEnumRendersPlaceholderValue(): void
    {
        $markdown = $this->combinedGenerator()->generate([DocumentFormat::class]);

        self::assertStringContainsString('| PDF | - |', $markdown);
        self::assertStringContainsString('| HTML | - |', $markdown);
    }

    /**
     * Test that the heading uses the component-name override.
     *
     * @return void
     */
    public function testHeadingUsesSchemaNameOverride(): void
    {
        $markdown = $this->combinedGenerator()->generate([AlternateTier::class]);

        self::assertStringContainsString("## AlternateTier\n", $markdown);
    }

    /**
     * Test that enums are ordered by component name regardless of input order.
     *
     * @return void
     */
    public function testOrdersEnumsByComponentName(): void
    {
        $markdown = $this->combinedGenerator()->generate([
            UserStatus::class,
            AlternateTier::class,
            DocumentFormat::class,
        ]);

        $alternate = strpos($markdown, '## AlternateTier');
        $document  = strpos($markdown, '## DocumentFormat');
        $status    = strpos($markdown, '## UserStatus');

        self::assertIsInt($alternate);
        self::assertIsInt($document);
        self::assertIsInt($status);
        self::assertLessThan($document, $alternate);
        self::assertLessThan($status, $document);
    }

    /**
     * Test that a flat reference, where every enum resolves to no module,
     * renders one H2 subsection per enum with no module headings.
     *
     * @return void
     */
    public function testCombinedOutputUsesEnumLevelHeadingsOnly(): void
    {
        $markdown = $this->combinedGenerator()->generate([UserStatus::class]);

        self::assertStringContainsString("## UserStatus\n", $markdown);
        self::assertStringNotContainsString('### ', $markdown);
    }

    /**
     * Test that a modular reference renders the shared section first, then one
     * module H2 per module sorted by name, each carrying its enums as H3
     * subsections.
     *
     * @return void
     */
    public function testGroupedOutputRendersCommonThenModuleHeadingsWithEnumSubsections(): void
    {
        $markdown = $this->groupedGenerator()->generate([
            DocumentFormat::class,
            UserStatus::class,
            UserLevel::class,
        ]);

        $common   = strpos($markdown, '## Common');
        $account  = strpos($markdown, '## Account');
        $billing  = strpos($markdown, '## Billing');
        $document = strpos($markdown, '### DocumentFormat');
        $status   = strpos($markdown, '### UserStatus');

        self::assertIsInt($common);
        self::assertIsInt($account);
        self::assertIsInt($billing);
        self::assertIsInt($document);
        self::assertIsInt($status);
        self::assertLessThan($account, $common);
        self::assertLessThan($billing, $account);
    }

    /**
     * Test that a grouped reference with no shared enums omits the Common
     * section and still renders the module sections.
     *
     * @return void
     */
    public function testGroupedOutputOmitsCommonWhenNoSharedEnums(): void
    {
        $markdown = $this->groupedGenerator()->generate([UserStatus::class]);

        self::assertStringNotContainsString('## Common', $markdown);
        self::assertStringContainsString("## Account\n", $markdown);
        self::assertStringContainsString('### UserStatus', $markdown);
    }

    /**
     * Test that an empty reference still renders the banner and heading with no
     * module or enum subsections.
     *
     * @return void
     */
    public function testEmptyReferenceRendersHeadingOnly(): void
    {
        $markdown = $this->combinedGenerator()->generate([]);

        self::assertStringContainsString("\n# Enum Reference\n", $markdown);
        self::assertStringNotContainsString('## ', $markdown);
    }

    /**
     * Build a generator whose resolver groups nothing, yielding combined
     * output.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\EnumReferenceDocGenerator
     */
    private function combinedGenerator(): EnumReferenceDocGenerator
    {
        return new EnumReferenceDocGenerator(new MappedModuleResolver);
    }

    /**
     * Build a generator whose resolver maps the fixture enums to modules,
     * leaving the pure enum shared so the Common section is exercised.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\EnumReferenceDocGenerator
     */
    private function groupedGenerator(): EnumReferenceDocGenerator
    {
        return new EnumReferenceDocGenerator(new MappedModuleResolver([
            UserStatus::class => new Module('App\Account', 'Account'),
            UserLevel::class  => new Module('App\Billing', 'Billing'),
        ]));
    }
}
