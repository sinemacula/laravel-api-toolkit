<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
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
     * Test that the full shared query-parameter set is emitted under its
     * canonical component names.
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
     * Test that every parameter is a query parameter carrying its conventional
     * request name.
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

        foreach (self::STRUCTURAL_OPERATORS as $token) {
            self::assertContains($token, $operators);
        }
    }

    /**
     * Test that the filter parameter enumerates exactly the 11 registered plus
     * 4 structural operators -- the full 11+4 vocabulary.
     *
     * @return void
     */
    public function testFilterParameterEnumeratesTheFullElevenPlusFourVocabulary(): void
    {
        $operators = $this->makeBuilder()->build()['Filters']['schema']['x-operators'];

        self::assertCount(15, $operators);
    }

    /**
     * Test that the filter description names the operators so consumers learn
     * the grammar at the pattern level.
     *
     * @return void
     */
    public function testFilterDescriptionNamesTheOperatorGrammar(): void
    {
        $filter = $this->makeBuilder()->build()['Filters'];

        self::assertStringContainsString('$eq', $filter['description']);
        self::assertStringContainsString('$and', $filter['description']);
    }

    /**
     * Test that the filter parameter does not declare a closed per-resource
     * field allow-list, so it never over-claims precision.
     *
     * @return void
     */
    public function testFilterParameterDeclaresNoPerResourceAllowList(): void
    {
        $schema = $this->makeBuilder()->build()['Filters']['schema'];

        self::assertArrayNotHasKey('enum', $schema);
        self::assertArrayNotHasKey('properties', $schema);
    }

    /**
     * Test that the filter parameter is the JSON-carrying string the parser
     * accepts rather than a bracketed deep object, which the parser rejects.
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
     * Test that the filter description states the grammar up front and defers
     * the accepted fields to the resource, keeping both clauses in order.
     *
     * @return void
     */
    public function testFilterDescriptionStatesTheGrammarAndDefersFieldsToTheResource(): void
    {
        $description = $this->makeBuilder()->build()['Filters']['description'];

        self::assertStringStartsWith('Generic filter grammar. Filters are a URL-encoded JSON object keyed by field', $description);
        self::assertStringEndsWith('each resource accepts only the fields it declares filterable.', $description);
    }

    /**
     * Test that the operator vocabulary reflects registry overrides rather than
     * a hard-coded list.
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
     * Test that a page-size ceiling configured off leaves the schema unbounded
     * rather than publishing a maximum no request is held to.
     *
     * @return void
     */
    public function testDisabledPageSizeCeilingLeavesTheLimitUnbounded(): void
    {
        $schema = $this->makeBuilder(0)->build()['Limit']['schema'];

        self::assertArrayNotHasKey('maximum', $schema);
        self::assertSame(1, $schema['minimum']);
    }

    /**
     * Test that a catalogue reporting no page-size bound at all is read as no
     * ceiling rather than as one, so a bound the catalogue has yet to learn
     * about is never invented for it.
     *
     * @return void
     */
    public function testAnUnreportedPageSizeCeilingLeavesTheLimitUnbounded(): void
    {
        $schema = $this->builderReporting([])->build()['Limit']['schema'];

        self::assertArrayNotHasKey('maximum', $schema);
        self::assertSame(1, $schema['minimum']);
    }

    /**
     * Test that the pagination-mode parameter documents cursor as its only
     * behaviour-changing value and describes the default length-aware mode.
     *
     * @return void
     */
    public function testPaginationModeParameterEnumeratesCursorOnly(): void
    {
        $pagination = $this->makeBuilder()->build()['Pagination'];

        self::assertSame(['type' => 'string', 'enum' => ['cursor']], $pagination['schema']);
        self::assertStringContainsString('length-aware', $pagination['description']);
        self::assertStringContainsString('cursor', $pagination['description']);
    }

    /**
     * Test that the sparse-fieldset parameter carries an object schema of
     * string members, is optional, and uses the deepObject exploded style.
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
     * Test that every deep-object parameter carries the deepObject exploded
     * style so bracketed keys serialise per the toolkit's query grammar.
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
     * Test that each parameter description carries the canonical query-grammar
     * example token a developer copies verbatim.
     *
     * @return void
     */
    public function testDescriptionsCarryTheCanonicalGrammarExampleTokens(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertStringContainsString('fields[users]=id,name', $parameters['Fields']['description']);
        self::assertStringContainsString('search=John Smith', $parameters['Search']['description']);
        self::assertStringContainsString('order=name,created_at:desc', $parameters['Order']['description']);
        self::assertStringContainsString('counts[users]=posts', $parameters['Counts']['description']);
        self::assertStringContainsString('sums[users][posts]=id', $parameters['Sums']['description']);
        self::assertStringContainsString('averages[users][posts]=id', $parameters['Averages']['description']);
        self::assertStringContainsString('filters={"status":{"$eq":"active"}}', $parameters['Filters']['description']);
        self::assertStringContainsString('trashed=with', $parameters['Trashed']['description']);
    }

    /**
     * Test that the pagination-family descriptions state the behaviour each
     * parameter controls so consumers pick the correct paging knob.
     *
     * @return void
     */
    public function testPaginationFamilyDescriptionsStateTheirBehaviour(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertStringContainsString('maximum number of records', $parameters['Limit']['description']);
        self::assertStringContainsString('Page number', $parameters['Page']['description']);
        self::assertStringContainsString('cursor', $parameters['Cursor']['description']);
    }

    /**
     * Test that a parameter without an explicit style omits both the style and
     * explode keys.
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
     * Test that the search parameter is a plain string schema whose description
     * states the two limits a consumer cannot infer: that it reaches the
     * requested resource only, and that a term has a floor.
     *
     * @return void
     */
    public function testSearchParameterIsAStringSchemaStatingItsLimits(): void
    {
        $search = $this->makeBuilder()->build()['Search'];

        self::assertSame(['type' => 'string'], $search['schema']);

        // The minimum bounds each word rather than the term, so a long term
        // built from short words is refused; the description must say which.
        self::assertSame(
            'Free-text search across the fields a resource declares searchable, e.g. search=John Smith. '
            . 'It matches the requested resource only and never traverses a relation; a term carrying a word shorter than the configured minimum is rejected, '
            . 'as is one longer, or carrying more words, than the configured bounds allow.',
            $search['description'],
        );
    }

    /**
     * Test that the relation-aggregate parameters describe their nested
     * string-keyed object maps.
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
     * Test that the soft-delete visibility parameter offers only the two values
     * that widen the default live-records-only scope.
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
     * Test that the soft-delete visibility description states the default and
     * that a resource has to opt in, neither of which a client can infer from
     * an unchanged result set.
     *
     * @return void
     */
    public function testTrashedDescriptionStatesTheDefaultAndTheOptIn(): void
    {
        $description = $this->makeBuilder()->build()['Trashed']['description'];

        self::assertStringStartsWith('Soft-delete visibility: with returns soft-deleted records alongside the live ones', $description);
        self::assertStringContainsString('Omitting it returns live records only.', $description);
        self::assertStringContainsString('has not opted in', $description);
    }

    /**
     * Test that an index accepts the whole grammar, in the order an operation
     * carries it.
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
     * Test that a show accepts what shapes and scopes a single record, and none
     * of the collection selection grammar.
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
     * Test that a read of a model that does not soft delete is never offered
     * the visibility parameter, the server having nothing to widen: a parameter
     * it is bound to discard would be the quiet no-op the package refuses.
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
     * Test that an operation whose model cannot be resolved is treated the same
     * way, so an unresolvable controller never advertises a widening the server
     * may not honour.
     *
     * @return void
     */
    public function testReadsWithNoResolvedModelOmitVisibility(): void
    {
        self::assertNotContains('Trashed', $this->referencedNames('index'));
        self::assertNotContains('Trashed', $this->referencedNames('show'));
    }

    /**
     * Test that a write of a soft-deleting model is still offered nothing but
     * the shaping grammar, the widening belonging to a read alone.
     *
     * @return void
     */
    public function testWritesOfASoftDeletingModelStillAcceptShapingOnly(): void
    {
        self::assertSame(['Fields', 'Counts', 'Sums', 'Averages'], $this->referencedNames('store', Article::class));
        self::assertSame(['Fields', 'Counts', 'Sums', 'Averages'], $this->referencedNames('update', Article::class));
    }

    /**
     * Test that a store and an update accept only what shapes the resource they
     * return, so neither claims to page or filter a collection nor to widen the
     * soft-delete scope.
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
     * Test that a destroy and an action outside the REST set accept nothing, so
     * an empty response body is never documented as shapeable.
     *
     * @return void
     */
    public function testDestroyAndUnknownActionsAcceptNothing(): void
    {
        self::assertSame([], $this->makeBuilder()->referencesFor('destroy'));
        self::assertSame([], $this->makeBuilder()->referencesFor('report'));
    }

    /**
     * Test that every referenced name resolves to an emitted component, so no
     * operation can point at a parameter the document does not define.
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
     * Test that a reference is emitted as a component pointer rather than an
     * inlined copy of the definition.
     *
     * @return void
     */
    public function testReferencesArePointersRatherThanInlinedDefinitions(): void
    {
        $reference = $this->makeBuilder()->referencesFor('show')[0];

        self::assertSame(['$ref' => '#/components/parameters/Fields'], $reference);
    }

    /**
     * List the component names the given action references, stripped of the
     * component path prefix.
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
     * Build a QueryParameterBuilder backed by a stub returning the default 11+4
     * operator vocabulary and the given page-size ceiling.
     *
     * @param  int  $ceiling
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder
     */
    private function makeBuilder(int $ceiling = 100): QueryParameterBuilder
    {
        return $this->builderReporting([QueryParameterValidator::MAX_LIMIT => $ceiling]);
    }

    /**
     * Build a QueryParameterBuilder over a catalogue reporting exactly the
     * given request bounds.
     *
     * @param  array<string, int>  $limits
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder
     */
    private function builderReporting(array $limits): QueryParameterBuilder
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getOperatorTokens')->willReturn(self::OPERATOR_TOKENS);
        $catalogue->method('getStructuralOperators')->willReturn(self::STRUCTURAL_OPERATORS);
        $catalogue->method('getQueryLimits')->willReturn($limits);

        return new QueryParameterBuilder($catalogue);
    }
}
