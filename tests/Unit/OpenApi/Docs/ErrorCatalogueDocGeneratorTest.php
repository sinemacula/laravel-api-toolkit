<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\ErrorCatalogueDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use Tests\TestCase;

/**
 * Tests for the error catalogue documentation generator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ErrorCatalogueDocGenerator::class)]
final class ErrorCatalogueDocGeneratorTest extends TestCase
{
    /**
     * Test that the generator emits the banner, heading, and a well-formed
     * table header.
     *
     * @return void
     */
    public function testEmitsBannerHeadingAndTableHeader(): void
    {
        $markdown = (new ErrorCatalogueDocGenerator)->generate([
            new ErrorDescriptor(1000, 400, 'Bad Request', 'The request was malformed.'),
        ]);

        self::assertStringStartsWith('<!-- This file is auto-generated.', $markdown);
        self::assertStringContainsString("\n# Error Catalogue\n", $markdown);
        self::assertStringContainsString("| Name | Code | HTTP | Description |\n| --- | --- | --- | --- |\n", $markdown);
        self::assertStringEndsWith("\n", $markdown);
    }

    /**
     * Test that rows are sorted ascending by code regardless of input order.
     *
     * @return void
     */
    public function testSortsRowsByCodeAscending(): void
    {
        $markdown = (new ErrorCatalogueDocGenerator)->generate([
            new ErrorDescriptor(3000, 500, 'Server Error', 'Something failed.'),
            new ErrorDescriptor(1000, 400, 'Bad Request', 'Malformed.'),
            new ErrorDescriptor(2000, 404, 'Not Found', 'Missing.'),
        ]);

        $first  = strpos($markdown, '| 1000 |');
        $second = strpos($markdown, '| 2000 |');
        $third  = strpos($markdown, '| 3000 |');

        self::assertIsInt($first);
        self::assertIsInt($second);
        self::assertIsInt($third);
        self::assertLessThan($second, $first);
        self::assertLessThan($third, $second);
    }

    /**
     * Test that a null title falls back to a derived name.
     *
     * @return void
     */
    public function testFallsBackToDerivedNameWhenTitleIsNull(): void
    {
        $markdown = (new ErrorCatalogueDocGenerator)->generate([
            new ErrorDescriptor(4000, 500, null, 'No title defined.'),
        ]);

        self::assertStringContainsString('| Error 4000 | 4000 | 500 | No title defined. |', $markdown);
    }

    /**
     * Test that a pipe in the detail is escaped so the table stays well-formed.
     *
     * @return void
     */
    public function testEscapesPipeInDetail(): void
    {
        $markdown = (new ErrorCatalogueDocGenerator)->generate([
            new ErrorDescriptor(5000, 422, 'Invalid', 'Use a|b syntax.'),
        ]);

        self::assertStringContainsString('Use a\|b syntax.', $markdown);
        self::assertStringNotContainsString('Use a|b syntax.', $markdown);
    }
}
