<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Docs;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\OpenApi\Docs\Module;
use SineMacula\ApiToolkit\OpenApi\Docs\ModuleSectionGrouper;
use SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use Tests\Fixtures\OpenApi\MappedModuleResolver;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the query surface documentation generator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QuerySurfaceDocGenerator::class)]
final class QuerySurfaceDocGeneratorTest extends TestCase
{
    /**
     * Test that the generator emits the banner and heading.
     *
     * @return void
     */
    public function testEmitsBannerAndHeading(): void
    {
        $markdown = $this->combinedGenerator()->generate([], [], []);

        self::assertStringStartsWith('<!-- This file is auto-generated.', $markdown);
        self::assertStringContainsString("\n# Query Surface Reference\n", $markdown);
        self::assertStringEndsWith("\n", $markdown);
    }

    /**
     * Test that the note names only parameters a column key is sent under.
     *
     * @return void
     */
    public function testKeyNoteNamesOnlyTheParametersAKeyIsSentUnder(): void
    {
        $markdown = $this->combinedGenerator()->generate([], [], []);

        self::assertStringContainsString(
            'Key is the name to send in `filters` and `order`, and the column a `search` matches on.',
            $markdown,
        );
        self::assertStringNotContainsString('send in `filters`, `order`, and `search`', $markdown);
    }

    /**
     * Test that the note calls an index-backed order the resource's claim.
     *
     * @return void
     */
    public function testOrderNoteSaysWhatIndexBackedClaims(): void
    {
        $markdown = $this->combinedGenerator()->generate([], [], []);

        self::assertStringContainsString(
            'An order reads as Index-backed where the resource recorded no exemption for it; the index itself is proven by schema validation,',
            $markdown,
        );
    }

    /**
     * Test that the page-size ceiling is tabled with the structural caps.
     *
     * @return void
     */
    public function testPageSizeCeilingIsTabledAlongsideTheStructuralCaps(): void
    {
        $markdown = $this->combinedGenerator()->generate([], ['max_limit' => 100], []);

        self::assertStringContainsString(
            '| `max_limit` | 100 | The records one page may carry, asked for with `limit`. |',
            $markdown,
        );
    }

    /**
     * Test that a column without dispatchable operators answers no filter.
     *
     * @return void
     */
    public function testColumnWithNoOperatorsIsTabledAsAnsweringNoFilter(): void
    {
        $markdown = $this->combinedGenerator()->generate([$this->surface(UserResource::class, [
            new QueryColumnDescriptor(property: 'notes', column: 'notes', sortable: true),
        ])], [], []);

        self::assertStringContainsString('| `notes` | `notes` | - | - | Index-backed | - |', $markdown);
    }

    /**
     * Test that each structural cap is tabled with its value and meaning.
     *
     * @return void
     */
    public function testRequestLimitsTableCarriesEachCapAndItsValue(): void
    {
        $markdown = $this->combinedGenerator()->generate([], ['max_nodes' => 100, 'max_offset' => 10000], []);

        self::assertStringContainsString("\n\n## Request Limits\n", $markdown);
        self::assertStringContainsString("| Limit | Value | Bounds |\n| --- | --- | --- |\n", $markdown);
        self::assertStringContainsString('| `max_nodes` | 100 | The keys a filter visits in total. |', $markdown);
        self::assertStringContainsString('| `max_offset` | 10000 | The rows a paginated read may scan past to reach its page. |', $markdown);
    }

    /**
     * Test that a cap configured off is reported as disabled, not zero.
     *
     * @return void
     */
    public function testDisabledCapIsReportedAsDisabled(): void
    {
        $markdown = $this->combinedGenerator()->generate([], ['max_depth' => 0], []);

        self::assertStringContainsString('| `max_depth` | Disabled |', $markdown);
    }

    /**
     * Test that a cap without a description is still tabled with its value.
     *
     * @return void
     */
    public function testUndescribedCapIsStillTabledWithItsValue(): void
    {
        $markdown = $this->combinedGenerator()->generate([], ['max_something' => 7], []);

        self::assertStringContainsString('| `max_something` | 7 | - |', $markdown);
    }

    /**
     * Test that the search term bounds are tabled under their reading names.
     *
     * @return void
     */
    public function testSearchTermBoundsTableCarriesEachBound(): void
    {
        $markdown = $this->combinedGenerator()->generate([], [], [
            'min_word_length' => 3,
            'max_length'      => 128,
            'max_words'       => 10,
        ]);

        self::assertStringContainsString("\n\n## Search Term Bounds\n", $markdown);
        self::assertStringContainsString('| Shortest word, in characters | 3 |', $markdown);
        self::assertStringContainsString('| Longest term, in characters | 128 |', $markdown);
        self::assertStringContainsString('| Most whitespace-separated words | 10 |', $markdown);
    }

    /**
     * Test that a bound without a reading name falls back to its own name.
     *
     * @return void
     */
    public function testUnnamedBoundFallsBackToItsOwnName(): void
    {
        $markdown = $this->combinedGenerator()->generate([], [], ['max_phrases' => 2]);

        self::assertStringContainsString('| max_phrases | 2 |', $markdown);
    }

    /**
     * Test that the rejection section carries the status, code and meta keys.
     *
     * @return void
     */
    public function testRejectionSectionCarriesTheStatusCodeAndMetaKeys(): void
    {
        $markdown = $this->combinedGenerator()->generate([], ['max_in_items' => 500], []);

        self::assertStringContainsString("\n\n## Over-Budget Rejection\n", $markdown);
        self::assertStringContainsString('"status": 422,', $markdown);
        self::assertStringContainsString('"code": 10201,', $markdown);
        self::assertStringContainsString('"reason": "max_in_items",', $markdown);
        self::assertStringContainsString('"limit": 500,', $markdown);
        self::assertStringContainsString('"actual": 501', $markdown);
    }

    /**
     * Test that an unreported bound reads as no bound in the rejection.
     *
     * @return void
     */
    public function testRejectionExampleReportsNothingForAnUnreportedBound(): void
    {
        $markdown = $this->combinedGenerator()->generate([], [], []);

        self::assertStringContainsString('"limit": 0,', $markdown);
        self::assertStringContainsString('"actual": 1', $markdown);
    }

    /**
     * Test that the worked rejection reads the bound from the limits table.
     *
     * @return void
     */
    public function testRejectionExampleTracksTheConfiguredBound(): void
    {
        $markdown = $this->combinedGenerator()->generate([], ['max_in_items' => 50], []);

        self::assertStringContainsString('| `max_in_items` | 50 |', $markdown);
        self::assertStringContainsString('"limit": 50,', $markdown);
        self::assertStringContainsString('"actual": 51', $markdown);
        self::assertStringNotContainsString('500', $markdown);
    }

    /**
     * Test that the rejection section explains every envelope meta key.
     *
     * @return void
     */
    public function testRejectionSectionExplainsWhatTheEnvelopeReports(): void
    {
        $markdown = $this->combinedGenerator()->generate([], [], []);

        self::assertStringContainsString(
            'A request over one of these limits is answered with the standard error envelope, whose `meta` names the limit,'
            . "\nthe parameter at fault, the position within it, and the value supplied:",
            $markdown,
        );

        self::assertStringContainsString(
            'The `reason` names the limit as the table above spells it, and the `pointer` is empty where a limit bounds'
            . "\na parameter as a whole rather than a position within it. The title and detail the envelope carries"
            . "\nalongside are the ones the error catalogue lists for this code.",
            $markdown,
        );
    }

    /**
     * Test that a filterable column is tabled with capability and operators.
     *
     * @return void
     */
    public function testFilterableColumnIsTabledWithItsCapabilityAndOperators(): void
    {
        $markdown = $this->combinedGenerator()->generate([$this->surface(UserResource::class, [
            new QueryColumnDescriptor(
                property  : 'status',
                column    : 'status',
                capability: Capability::ENUM,
                operators : ['$eq', '$in', '$neq', '$null', '$notNull'],
            ),
        ])], [], []);

        self::assertStringContainsString(
            "| Field | Key | Filter | Operators | Order | Search |\n| --- | --- | --- | --- | --- | --- |\n",
            $markdown,
        );
        self::assertStringContainsString(
            '| `status` | `status` | `enum` | `$eq`, `$in`, `$neq`, `$null`, `$notNull` | - | - |',
            $markdown,
        );
    }

    /**
     * Test that an aliased column is tabled under property and column.
     *
     * @return void
     */
    public function testAliasedColumnIsTabledUnderBothNames(): void
    {
        $markdown = $this->combinedGenerator()->generate([$this->surface(UserResource::class, [
            new QueryColumnDescriptor(property: 'email', column: 'email_address', strategy: SearchStrategy::PREFIX),
        ])], [], []);

        self::assertStringContainsString('| `email` | `email_address` | - | - | - | `prefix` |', $markdown);
    }

    /**
     * Test that an indexed order is tabled as index-backed.
     *
     * @return void
     */
    public function testOrderAnIndexHoldsIsTabledAsIndexBacked(): void
    {
        $markdown = $this->combinedGenerator()->generate([$this->surface(UserResource::class, [
            new QueryColumnDescriptor(property: 'created_at', column: 'created_at', sortable: true),
        ])], [], []);

        self::assertStringContainsString('| `created_at` | `created_at` | - | - | Index-backed | - |', $markdown);
    }

    /**
     * Test that an exempted order is tabled as unindexed with escaped reason.
     *
     * @return void
     */
    public function testExemptedOrderIsTabledWithItsEscapedReason(): void
    {
        $markdown = $this->combinedGenerator()->generate([$this->surface(UserResource::class, [
            new QueryColumnDescriptor(
                property       : 'notes',
                column         : 'notes',
                sortable       : true,
                unindexedReason: "the table is small | and\nbounded",
            ),
        ])], [], []);

        self::assertStringContainsString('| Unindexed: the table is small \| and bounded | - |', $markdown);
    }

    /**
     * Test that a reason recorded against an unordered column is not shown.
     *
     * @return void
     */
    public function testRecordedReasonIsNotRenderedWithoutAnOrder(): void
    {
        $markdown = $this->combinedGenerator()->generate([$this->surface(UserResource::class, [
            new QueryColumnDescriptor(
                property       : 'notes',
                column         : 'notes',
                capability     : Capability::OPAQUE,
                operators      : ['$eq'],
                unindexedReason: 'recorded against no order',
            ),
        ])], [], []);

        self::assertStringNotContainsString('recorded against no order', $markdown);
    }

    /**
     * Test that a resource answering no query says so plainly.
     *
     * @return void
     */
    public function testResourceAnsweringNothingSaysSoPlainly(): void
    {
        $markdown = $this->combinedGenerator()->generate([$this->surface(OrganizationResource::class, [])], [], []);

        self::assertStringContainsString("\n\n## Organization\n", $markdown);
        self::assertStringContainsString("\nThis resource answers no filter, order, or search.\n", $markdown);
        self::assertStringNotContainsString('| Field | Key |', $markdown);
    }

    /**
     * Test that traversable relations are named, or their absence stated.
     *
     * @return void
     */
    public function testTraversableRelationsAreNamedAndAbsenceIsStated(): void
    {
        $named = $this->combinedGenerator()->generate(
            [new QuerySurfaceDescriptor(UserResource::class, [], ['organization', 'posts'])],
            [],
            [],
        );

        $none = $this->combinedGenerator()->generate([$this->surface(UserResource::class, [])], [], []);

        self::assertStringContainsString("\n\nTraversable relations: `organization`, `posts`.\n", $named);
        self::assertStringContainsString("\n\nTraversable relations: none.\n", $none);
    }

    /**
     * Test that a flat reference renders one H2 per resource and no modules.
     *
     * @return void
     */
    public function testCombinedOutputUsesResourceLevelHeadingsOnly(): void
    {
        $markdown = $this->combinedGenerator()->generate([$this->surface(UserResource::class, [])], [], []);

        self::assertStringContainsString("\n\n## User\n", $markdown);
        self::assertStringNotContainsString('### ', $markdown);
    }

    /**
     * Test that resources are ordered by component name.
     *
     * @return void
     */
    public function testOrdersResourcesByComponentName(): void
    {
        $markdown = $this->combinedGenerator()->generate([
            $this->surface(UserResource::class, []),
            $this->surface(OrganizationResource::class, []),
        ], [], []);

        self::assertStringContainsString("\n\n## Organization\n", $markdown);
        self::assertStringContainsString("\n\n## User\n", $markdown);
        self::assertLessThan(strpos($markdown, '## User'), strpos($markdown, '## Organization'));
    }

    /**
     * Test that a modular reference renders shared first, then sorted modules.
     *
     * @return void
     */
    public function testGroupedOutputRendersCommonThenModuleHeadingsWithResourceSubsections(): void
    {
        $markdown = $this->groupedMarkdown();

        $common  = strpos($markdown, '## Common');
        $account = strpos($markdown, '## Account');
        $billing = strpos($markdown, '## Billing');

        self::assertIsInt($common);
        self::assertIsInt($account);
        self::assertIsInt($billing);
        self::assertStringContainsString('### Tag', $markdown);
        self::assertStringContainsString('### Organization', $markdown);
        self::assertLessThan($account, $common);
        self::assertLessThan($billing, $account);
    }

    /**
     * Test that a module gathers and orders all of its resources by name.
     *
     * @return void
     */
    public function testModuleGathersEveryResourceOrderedByComponentName(): void
    {
        $markdown = $this->groupedMarkdown();

        $post = strpos($markdown, '### Post');
        $user = strpos($markdown, '### User');

        self::assertIsInt($post);
        self::assertIsInt($user);
        self::assertLessThan($user, $post);
    }

    /**
     * Test that a reference without shared resources omits Common.
     *
     * @return void
     */
    public function testGroupedOutputOmitsCommonWhenNoSharedResources(): void
    {
        $markdown = $this->groupedGenerator()->generate([$this->surface(UserResource::class, [])], [], []);

        self::assertStringNotContainsString('## Common', $markdown);
        self::assertStringContainsString("\n\n## Account\n", $markdown);
        self::assertStringContainsString('### User', $markdown);
    }

    /**
     * Test that a reference without resources still renders the bounds.
     *
     * @return void
     */
    public function testEmptyReferenceStillRendersTheRequestBounds(): void
    {
        $markdown = $this->combinedGenerator()->generate([], ['max_nodes' => 100], []);

        self::assertStringContainsString('| `max_nodes` | 100 |', $markdown);
        self::assertStringNotContainsString('Traversable relations:', $markdown);
    }

    /**
     * Build a surface descriptor for the given resource and columns.
     *
     * @param  class-string  $resourceClass
     * @param  array<int, \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>  $columns
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function surface(string $resourceClass, array $columns): QuerySurfaceDescriptor
    {
        return new QuerySurfaceDescriptor($resourceClass, $columns);
    }

    /**
     * Build a generator whose grouper resolves no module.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator
     */
    private function combinedGenerator(): QuerySurfaceDocGenerator
    {
        return new QuerySurfaceDocGenerator(new ModuleSectionGrouper(new MappedModuleResolver));
    }

    /**
     * Build a generator whose grouper maps the fixture resources to modules.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Docs\QuerySurfaceDocGenerator
     */
    private function groupedGenerator(): QuerySurfaceDocGenerator
    {
        return new QuerySurfaceDocGenerator(new ModuleSectionGrouper(new MappedModuleResolver([
            UserResource::class         => new Module('App\Account', 'Account'),
            PostResource::class         => new Module('App\Account', 'Account'),
            OrganizationResource::class => new Module('App\Billing', 'Billing'),
        ])));
    }

    /**
     * Render the grouped reference from a deliberately unordered registration.
     *
     * @return string
     */
    private function groupedMarkdown(): string
    {
        return $this->groupedGenerator()->generate([
            $this->surface(OrganizationResource::class, []),
            $this->surface(UserResource::class, []),
            $this->surface(PostResource::class, []),
            $this->surface(TagResource::class, []),
        ], [], []);
    }
}
