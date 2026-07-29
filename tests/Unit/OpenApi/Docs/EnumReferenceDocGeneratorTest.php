<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\EnumReferenceDocGenerator;
use Tests\Fixtures\Enums\Alternate\Tier as AlternateTier;
use Tests\Fixtures\Enums\DocumentFormat;
use Tests\Fixtures\Enums\UserLevel;
use Tests\Fixtures\Enums\UserStatus;
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
        $markdown = (new EnumReferenceDocGenerator)->generate([UserStatus::class]);

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
        $markdown = (new EnumReferenceDocGenerator)->generate([UserStatus::class]);

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
        $markdown = (new EnumReferenceDocGenerator)->generate([UserLevel::class]);

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
        $markdown = (new EnumReferenceDocGenerator)->generate([DocumentFormat::class]);

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
        $markdown = (new EnumReferenceDocGenerator)->generate([AlternateTier::class]);

        self::assertStringContainsString("## AlternateTier\n", $markdown);
    }

    /**
     * Test that enums are ordered by component name regardless of input order.
     *
     * @return void
     */
    public function testOrdersEnumsByComponentName(): void
    {
        $markdown = (new EnumReferenceDocGenerator)->generate([
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
}
