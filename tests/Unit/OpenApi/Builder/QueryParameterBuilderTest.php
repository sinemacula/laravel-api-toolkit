<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;

/**
 * Tests for the QueryParameterBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryParameterBuilder::class)]
final class QueryParameterBuilderTest extends TestCase
{
    /** @var array<int, string> The eleven registered operator tokens */
    private const array OPERATOR_TOKENS = [
        '$eq', '$neq', '$gt', '$lt', '$ge', '$le',
        '$in', '$between', '$contains', '$null', '$notNull',
    ];

    /** @var array<int, string> The four structural operators */
    private const array STRUCTURAL_OPERATORS = ['$and', '$or', '$has', '$hasnt'];

    /**
     * Test that the full shared query-parameter set is emitted.
     *
     * @return void
     */
    public function testEmitsTheFullSharedParameterSet(): void
    {
        $parameters = $this->makeBuilder()->build();

        foreach (['Fields', 'Filters', 'Search', 'Order', 'Limit', 'Page', 'Cursor', 'Pagination', 'Counts', 'Sums', 'Averages', 'Trashed'] as $name) {
            self::assertArrayHasKey($name, $parameters);
        }

        self::assertCount(12, $parameters);
    }

    /**
     * Test that every parameter is a query parameter with its request name.
     *
     * @return void
     */
    public function testParametersAreQueryParametersWithConventionalNames(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertSame('query', $parameters['Fields']['in']);
        self::assertSame('fields', $parameters['Fields']['name']);
        self::assertSame('filters', $parameters['Filters']['name']);
        self::assertSame('search', $parameters['Search']['name']);
        self::assertSame('order', $parameters['Order']['name']);
        self::assertSame('limit', $parameters['Limit']['name']);
        self::assertSame('page', $parameters['Page']['name']);
        self::assertSame('cursor', $parameters['Cursor']['name']);
        self::assertSame('pagination', $parameters['Pagination']['name']);
        self::assertSame('counts', $parameters['Counts']['name']);
        self::assertSame('sums', $parameters['Sums']['name']);
        self::assertSame('averages', $parameters['Averages']['name']);
        self::assertSame('trashed', $parameters['Trashed']['name']);
    }

    /**
     * Test that the filter parameter documents every registered operator token.
     *
     * @return void
     */
    public function testFilterParameterCoversEveryRegisteredOperator(): void
    {
        $operators = $this->makeBuilder()->build()['Filters']['schema']['x-operators'];

        self::assertIsIterable($operators);

        foreach (self::OPERATOR_TOKENS as $token) {
            self::assertContains($token, $operators);
        }
    }

    /**
     * Test that the filter parameter documents every structural operator.
     *
     * @return void
     */
    public function testFilterParameterCoversEveryStructuralOperator(): void
    {
        $operators = $this->makeBuilder()->build()['Filters']['schema']['x-operators'];

        self::assertIsIterable($operators);

        foreach (self::STRUCTURAL_OPERATORS as $token) {
            self::assertContains($token, $operators);
        }
    }

    /**
     * Test that the filter enumerates the 11 registered and 4 structural ops.
     *
     * @return void
     */
    public function testFilterParameterEnumeratesTheFullElevenPlusFourVocabulary(): void
    {
        $operators = $this->makeBuilder()->build()['Filters']['schema']['x-operators'];

        self::assertIsArray($operators);
        self::assertCount(15, $operators);
    }

    /**
     * Test that the filter description names the operators.
     *
     * @return void
     */
    public function testFilterDescriptionNamesTheOperatorGrammar(): void
    {
        $filter = $this->makeBuilder()->build()['Filters'];

        self::assertIsString($filter['description']);
        self::assertStringContainsString('$eq', $filter['description']);
        self::assertIsString($filter['description']);
        self::assertStringContainsString('$and', $filter['description']);
    }

    /**
     * Test that the filter declares no closed per-resource field allow-list.
     *
     * @return void
     */
    public function testFilterParameterDeclaresNoPerResourceAllowList(): void
    {
        $schema = $this->makeBuilder()->build()['Filters']['schema'];

        self::assertIsArray($schema);
        self::assertArrayNotHasKey('enum', $schema);
        self::assertIsArray($schema);
        self::assertArrayNotHasKey('properties', $schema);
    }

    /**
     * Test that the filter is a JSON string, not a bracketed deep object.
     *
     * @return void
     */
    public function testFilterParameterIsAJsonCarryingStringRatherThanADeepObject(): void
    {
        $filters = $this->makeBuilder()->build()['Filters'];

        self::assertSame('string', $filters['schema']['type']);
        self::assertSame('application/json', $filters['schema']['contentMediaType']);
        self::assertArrayNotHasKey('style', $filters);
        self::assertArrayNotHasKey('explode', $filters);
    }

    /**
     * Test that the filter description states the grammar, then the fields.
     *
     * @return void
     */
    public function testFilterDescriptionStatesTheGrammarAndDefersFieldsToTheResource(): void
    {
        $description = $this->makeBuilder()->build()['Filters']['description'];

        self::assertIsString($description);
        self::assertStringStartsWith('Generic filter grammar. Filters are a URL-encoded JSON object keyed by field', $description);
        self::assertIsString($description);
        self::assertStringEndsWith('each resource accepts only the fields it declares filterable.', $description);
    }

    /**
     * Test that the operator vocabulary reflects registry overrides.
     *
     * @return void
     */
    public function testOperatorVocabularyIsRegistryDriven(): void
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getOperatorTokens')->willReturn(['$custom']);
        $catalogue->method('getStructuralOperators')->willReturn(['$and']);

        $operators = (new QueryParameterBuilder($catalogue))->build()['Filters']['schema']['x-operators'];

        self::assertSame(['$custom', '$and'], $operators);
    }

    /**
     * Test that the pagination parameters carry sane integer constraints.
     *
     * @return void
     */
    public function testPaginationParametersAreConstrainedIntegers(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertSame('integer', $parameters['Limit']['schema']['type']);
        self::assertSame(1, $parameters['Limit']['schema']['minimum']);
        self::assertSame(100, $parameters['Limit']['schema']['maximum']);
        self::assertSame('integer', $parameters['Page']['schema']['type']);
        self::assertSame(1, $parameters['Page']['schema']['minimum']);
        self::assertSame('string', $parameters['Cursor']['schema']['type']);
    }

    /**
     * Test that a disabled page-size ceiling leaves the schema unbounded.
     *
     * @return void
     */
    public function testDisabledPageSizeCeilingLeavesTheLimitUnbounded(): void
    {
        $schema = $this->makeBuilder(0)->build()['Limit']['schema'];

        self::assertIsArray($schema);
        self::assertArrayNotHasKey('maximum', $schema);
        self::assertSame(1, $schema['minimum']);
    }

    /**
     * Test that a catalogue reporting no page-size bound means no ceiling.
     *
     * @return void
     */
    public function testAnUnreportedPageSizeCeilingLeavesTheLimitUnbounded(): void
    {
        $schema = $this->builderReporting([])->build()['Limit']['schema'];

        self::assertIsArray($schema);
        self::assertArrayNotHasKey('maximum', $schema);
        self::assertSame(1, $schema['minimum']);
    }

    /**
     * Test that the pagination-mode parameter documents cursor as its value.
     *
     * @return void
     */
    public function testPaginationModeParameterEnumeratesCursorOnly(): void
    {
        $pagination = $this->makeBuilder()->build()['Pagination'];

        self::assertSame(['type' => 'string', 'enum' => ['cursor']], $pagination['schema']);
        self::assertIsString($pagination['description']);
        self::assertStringContainsString('length-aware', $pagination['description']);
        self::assertIsString($pagination['description']);
        self::assertStringContainsString('cursor', $pagination['description']);
    }

    /**
     * Test the sparse-fieldset parameter schema, optionality and style.
     *
     * @return void
     */
    public function testFieldsParameterCarriesObjectSchemaAndDeepObjectStyle(): void
    {
        $fields = $this->makeBuilder()->build()['Fields'];

        self::assertSame(['type' => 'object', 'additionalProperties' => ['type' => 'string']], $fields['schema']);
        self::assertFalse($fields['required']);
        self::assertSame('deepObject', $fields['style']);
        self::assertTrue($fields['explode']);
    }

    /**
     * Test that every deep-object parameter uses the exploded deepObject style.
     *
     * @return void
     */
    public function testDeepObjectParametersCarryDeepObjectExplodedStyle(): void
    {
        $parameters = $this->makeBuilder()->build();

        foreach (['Fields', 'Counts', 'Sums', 'Averages'] as $name) {
            self::assertSame('deepObject', $parameters[$name]['style'], $name);
            self::assertTrue($parameters[$name]['explode'], $name);
        }
    }

    /**
     * Test that each description carries the canonical grammar example.
     *
     * @return void
     */
    public function testDescriptionsCarryTheCanonicalGrammarExampleTokens(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertIsString($parameters['Fields']['description']);
        self::assertStringContainsString('fields[users]=id,name', $parameters['Fields']['description']);
        self::assertIsString($parameters['Search']['description']);
        self::assertStringContainsString('search=John Smith', $parameters['Search']['description']);
        self::assertIsString($parameters['Order']['description']);
        self::assertStringContainsString('order=name,created_at:desc', $parameters['Order']['description']);
        self::assertIsString($parameters['Counts']['description']);
        self::assertStringContainsString('counts[users]=posts', $parameters['Counts']['description']);
        self::assertIsString($parameters['Sums']['description']);
        self::assertStringContainsString('sums[users][posts]=id', $parameters['Sums']['description']);
        self::assertIsString($parameters['Averages']['description']);
        self::assertStringContainsString('averages[users][posts]=id', $parameters['Averages']['description']);
        self::assertIsString($parameters['Filters']['description']);
        self::assertStringContainsString('filters={"status":{"$eq":"active"}}', $parameters['Filters']['description']);
        self::assertIsString($parameters['Trashed']['description']);
        self::assertStringContainsString('trashed=with', $parameters['Trashed']['description']);
    }

    /**
     * Test that the pagination descriptions state what each one controls.
     *
     * @return void
     */
    public function testPaginationFamilyDescriptionsStateTheirBehaviour(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertIsString($parameters['Limit']['description']);
        self::assertStringContainsString('maximum number of records', $parameters['Limit']['description']);
        self::assertIsString($parameters['Page']['description']);
        self::assertStringContainsString('Page number', $parameters['Page']['description']);
        self::assertIsString($parameters['Cursor']['description']);
        self::assertStringContainsString('cursor', $parameters['Cursor']['description']);
    }

    /**
     * Test that a parameter without a style omits style and explode.
     *
     * @return void
     */
    public function testNonStyledParametersOmitStyleAndExplode(): void
    {
        $order = $this->makeBuilder()->build()['Order'];

        self::assertArrayNotHasKey('style', $order);
        self::assertArrayNotHasKey('explode', $order);
    }

    /**
     * Test that the ordering parameter is a plain string schema.
     *
     * @return void
     */
    public function testOrderParameterIsAPlainStringSchema(): void
    {
        self::assertSame(['type' => 'string'], $this->makeBuilder()->build()['Order']['schema']);
    }

    /**
     * Test that the search description states its resource scope and floor.
     *
     * @return void
     */
    public function testSearchParameterIsAStringSchemaStatingItsLimits(): void
    {
        $search = $this->makeBuilder()->build()['Search'];

        self::assertSame(['type' => 'string', 'maxLength' => 128], $search['schema']);

        // The minimum bounds each word, not the whole term
        self::assertSame(
            'Free-text search across the fields a resource declares searchable, e.g. search=John Smith. '
            . 'It matches the requested resource only and never traverses a relation; a term carrying a word shorter than the configured minimum is rejected, '
            . 'as is one longer, or carrying more words, than the configured bounds allow. '
            . 'The length is counted once surrounding and repeated whitespace has been collapsed, so a term written with more characters than the maximum may still be accepted.',
            $search['description'],
        );
    }

    /**
     * Test that relation-aggregate parameters describe nested object maps.
     *
     * @return void
     */
    public function testDeepObjectSchemasDescribeNestedStringMaps(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertSame(
            ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            $parameters['Counts']['schema'],
        );
        self::assertSame(
            ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
            $parameters['Sums']['schema'],
        );
        self::assertSame(
            ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
            $parameters['Averages']['schema'],
        );
    }

    /**
     * Test that the visibility parameter offers only the two widening values.
     *
     * @return void
     */
    public function testTrashedParameterEnumeratesOnlyTheWideningValues(): void
    {
        $trashed = $this->makeBuilder()->build()['Trashed'];

        self::assertSame(['type' => 'string', 'enum' => ['with', 'only']], $trashed['schema']);
        self::assertFalse($trashed['required']);
    }

    /**
     * Test that the visibility description states the default and the opt-in.
     *
     * @return void
     */
    public function testTrashedDescriptionStatesTheDefaultAndTheOptIn(): void
    {
        $description = $this->makeBuilder()->build()['Trashed']['description'];

        self::assertIsString($description);
        self::assertStringStartsWith('Soft-delete visibility: with returns soft-deleted records alongside the live ones', $description);
        self::assertIsString($description);
        self::assertStringContainsString('Omitting it returns live records only.', $description);
        self::assertIsString($description);
        self::assertStringContainsString('has not opted in', $description);
    }

    /**
     * Test that an index accepts the whole grammar, in operation order.
     *
     * @return void
     */
    public function testIndexAcceptsTheWholeGrammar(): void
    {
        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages', 'Filters', 'Search', 'Order', 'Limit', 'Page', 'Cursor', 'Pagination', 'Trashed'],
            $this->referencedNames('index', Article::class),
        );
    }

    /**
     * Test that a show accepts single-record shaping and no selection grammar.
     *
     * @return void
     */
    public function testShowAcceptsShapingAndVisibilityOnly(): void
    {
        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages', 'Trashed'],
            $this->referencedNames('show', Article::class),
        );
    }

    /**
     * Test that a model without soft deletes gets no visibility parameter.
     *
     * @return void
     */
    public function testReadsOfAModelThatDoesNotSoftDeleteOmitVisibility(): void
    {
        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages', 'Filters', 'Search', 'Order', 'Limit', 'Page', 'Cursor', 'Pagination'],
            $this->referencedNames('index', User::class),
        );

        self::assertSame(
            ['Fields', 'Counts', 'Sums', 'Averages'],
            $this->referencedNames('show', User::class),
        );
    }

    /**
     * Test that an unresolvable model gets no visibility parameter either.
     *
     * @return void
     */
    public function testReadsWithNoResolvedModelOmitVisibility(): void
    {
        self::assertNotContains('Trashed', $this->referencedNames('index'));
        self::assertNotContains('Trashed', $this->referencedNames('show'));
    }

    /**
     * Test that a write of a soft-deleting model gets only shaping grammar.
     *
     * @return void
     */
    public function testWritesOfASoftDeletingModelStillAcceptShapingOnly(): void
    {
        self::assertSame(['Fields', 'Counts', 'Sums', 'Averages'], $this->referencedNames('store', Article::class));
        self::assertSame(['Fields', 'Counts', 'Sums', 'Averages'], $this->referencedNames('update', Article::class));
    }

    /**
     * Test that a store and an update accept only resource shaping.
     *
     * @return void
     */
    public function testWriteActionsAcceptShapingOnly(): void
    {
        $expected = ['Fields', 'Counts', 'Sums', 'Averages'];

        self::assertSame($expected, $this->referencedNames('store'));
        self::assertSame($expected, $this->referencedNames('update'));
    }

    /**
     * Test that a destroy and a non-REST action accept nothing.
     *
     * @return void
     */
    public function testDestroyAndUnknownActionsAcceptNothing(): void
    {
        self::assertSame([], $this->makeBuilder()->referencesFor('destroy'));
        self::assertSame([], $this->makeBuilder()->referencesFor('report'));
    }

    /**
     * Test that every referenced name resolves to an emitted component.
     *
     * @return void
     */
    public function testEveryReferencedParameterIsAnEmittedComponent(): void
    {
        $builder = $this->makeBuilder();
        $defined = array_keys($builder->build());

        foreach (['index', 'show', 'store', 'update', 'destroy'] as $action) {
            foreach ($builder->referencesFor($action) as $reference) {
                self::assertContains(
                    str_replace('#/components/parameters/', '', $reference['$ref']),
                    $defined,
                    $action,
                );
            }
        }
    }

    /**
     * Test that a reference is emitted as a component pointer.
     *
     * @return void
     */
    public function testReferencesArePointersRatherThanInlinedDefinitions(): void
    {
        $reference = $this->makeBuilder()->referencesFor('show')[0];

        self::assertSame(['$ref' => '#/components/parameters/Fields'], $reference);
    }

    /**
     * Test that the documented longest term is the one the server enforces.
     *
     * @return void
     */
    public function testSearchParameterCarriesTheConfiguredLongestTerm(): void
    {
        $search = $this->makeBuilder(longest: 64)->build()['Search'];

        self::assertSame(['type' => 'string', 'maxLength' => 64], $search['schema']);
    }

    /**
     * Test that an absent longest term leaves the search schema unbounded.
     *
     * @return void
     */
    public function testACatalogueNamingNoLongestTermLeavesTheSearchUnbounded(): void
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getOperatorTokens')->willReturn(self::OPERATOR_TOKENS);
        $catalogue->method('getStructuralOperators')->willReturn(self::STRUCTURAL_OPERATORS);
        $catalogue->method('getQueryLimits')->willReturn([QueryParameterValidator::MAX_LIMIT => 100]);
        $catalogue->method('getSearchBounds')->willReturn([]);

        $search = (new QueryParameterBuilder($catalogue))->build()['Search'];

        self::assertSame(['type' => 'string'], $search['schema']);
    }

    /**
     * Test that a longest term of zero is published as a refusal.
     *
     * Unlike the page size, zero does not lift the bound: every term is
     * refused.
     *
     * @return void
     */
    public function testALongestTermOfZeroIsPublishedRatherThanTreatedAsUnbounded(): void
    {
        $search = $this->makeBuilder(longest: 0)->build()['Search'];

        self::assertSame(['type' => 'string', 'maxLength' => 0], $search['schema']);
    }

    /**
     * Test that a negative longest term is left off the schema.
     *
     * @return void
     */
    public function testANegativeLongestTermIsLeftOffTheSchema(): void
    {
        $search = $this->makeBuilder(longest: -1)->build()['Search'];

        self::assertSame(['type' => 'string'], $search['schema']);
    }

    /**
     * List the component names the given action references, unprefixed.
     *
     * @param  string  $action
     * @param  class-string|null  $modelClass
     * @return array<int, string>
     */
    private function referencedNames(string $action, ?string $modelClass = null): array
    {
        return array_map(
            static fn (array $reference): string => str_replace('#/components/parameters/', '', $reference['$ref']),
            $this->makeBuilder()->referencesFor($action, $modelClass),
        );
    }

    /**
     * Build a QueryParameterBuilder with the default operators and ceiling.
     *
     * @param  int  $ceiling
     * @param  int  $longest
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder
     */
    private function makeBuilder(int $ceiling = 100, int $longest = 128): QueryParameterBuilder
    {
        return $this->builderReporting([QueryParameterValidator::MAX_LIMIT => $ceiling], $longest);
    }

    /**
     * Build a QueryParameterBuilder over a catalogue with the given bounds.
     *
     * @param  array<string, int>  $limits
     * @param  int  $longest
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder
     */
    private function builderReporting(array $limits, int $longest = 128): QueryParameterBuilder
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getOperatorTokens')->willReturn(self::OPERATOR_TOKENS);
        $catalogue->method('getStructuralOperators')->willReturn(self::STRUCTURAL_OPERATORS);
        $catalogue->method('getQueryLimits')->willReturn($limits);
        $catalogue->method('getSearchBounds')->willReturn([
            SearchTerm::MIN_WORD_LENGTH_KEY => 3,
            SearchTerm::MAX_LENGTH_KEY      => $longest,
            SearchTerm::MAX_WORDS_KEY       => 10,
        ]);

        return new QueryParameterBuilder($catalogue);
    }
}
