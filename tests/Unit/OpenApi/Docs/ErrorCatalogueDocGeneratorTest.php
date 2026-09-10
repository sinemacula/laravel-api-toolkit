<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Docs\ErrorCatalogueDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Docs\Module;
use SineMacula\ApiToolkit\OpenApi\Docs\ModuleSectionGrouper;
use SineMacula\ApiToolkit\OpenApi\Metadata\ErrorDescriptor;
use Tests\Fixtures\OpenApi\MappedModuleResolver;
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
        $markdown = $this->combinedGenerator()->generate([
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
        $markdown = $this->combinedGenerator()->generate([
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
        $markdown = $this->combinedGenerator()->generate([
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
        $markdown = $this->combinedGenerator()->generate([
            new ErrorDescriptor(5000, 422, 'Invalid', 'Use a|b syntax.'),
        ]);

        self::assertStringContainsString('Use a\|b syntax.', $markdown);
        self::assertStringNotContainsString('Use a|b syntax.', $markdown);
    }

    /**
     * Test that a flat catalogue, where every descriptor resolves to no module,
     * renders the single combined table with no module headings.
     *
     * @return void
     */
    public function testCombinedOutputHasNoModuleHeadings(): void
    {
        $markdown = $this->combinedGenerator()->generate([
            new ErrorDescriptor(1000, 400, 'Bad Request', 'Malformed.', 'App\Exceptions\BadRequest'),
        ]);

        self::assertStringNotContainsString('## ', $markdown);
        self::assertStringContainsString('| Bad Request | 1000 | 400 | Malformed. |', $markdown);
    }

    /**
     * Test that a modular catalogue renders the shared section first followed
     * by one module section per module sorted by name, each a table of its
     * codes.
     *
     * @return void
     */
    public function testGroupedOutputRendersCommonThenModulesByName(): void
    {
        $markdown = $this->groupedGenerator()->generate([
            new ErrorDescriptor(3000, 500, 'Server Error', 'Failed.', 'App\Exceptions\ServerError'),
            new ErrorDescriptor(2000, 402, 'Payment', 'Payment failed.', 'App\Billing\Exceptions\PaymentFailed'),
            new ErrorDescriptor(1000, 404, 'Missing', 'Not found.', 'App\Account\Exceptions\Missing'),
        ]);

        $common  = strpos($markdown, '## Common');
        $account = strpos($markdown, '## Account');
        $billing = strpos($markdown, '## Billing');

        self::assertIsInt($common);
        self::assertIsInt($account);
        self::assertIsInt($billing);
        self::assertLessThan($account, $common);
        self::assertLessThan($billing, $account);
    }

    /**
     * Test that a grouped catalogue with no shared codes omits the Common
     * section and still renders the module sections.
     *
     * @return void
     */
    public function testGroupedOutputOmitsCommonWhenNoSharedCodes(): void
    {
        $markdown = $this->groupedGenerator()->generate([
            new ErrorDescriptor(1000, 404, 'Missing', 'Not found.', 'App\Account\Exceptions\Missing'),
        ]);

        self::assertStringNotContainsString('## Common', $markdown);
        self::assertStringContainsString('## Account', $markdown);
    }

    /**
     * Test that an empty catalogue still renders the combined table header with
     * no module headings.
     *
     * @return void
     */
    public function testEmptyCatalogueRendersCombinedTableHeader(): void
    {
        $markdown = $this->combinedGenerator()->generate([]);

        self::assertStringContainsString("| Name | Code | HTTP | Description |\n| --- | --- | --- | --- |\n", $markdown);
        self::assertStringNotContainsString('## ', $markdown);
    }

    /**
     * Build a generator whose grouper resolves no module, yielding combined
     * output.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\ErrorCatalogueDocGenerator
     */
    private function combinedGenerator(): ErrorCatalogueDocGenerator
    {
        return new ErrorCatalogueDocGenerator(new ModuleSectionGrouper(new MappedModuleResolver));
    }

    /**
     * Build a generator whose grouper maps the fixture sources to modules.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\ErrorCatalogueDocGenerator
     */
    private function groupedGenerator(): ErrorCatalogueDocGenerator
    {
        return new ErrorCatalogueDocGenerator(new ModuleSectionGrouper(new MappedModuleResolver([
            'App\Billing\Exceptions\PaymentFailed' => new Module('App\Billing', 'Billing'),
            'App\Account\Exceptions\Missing'       => new Module('App\Account', 'Account'),
        ])));
    }
}
