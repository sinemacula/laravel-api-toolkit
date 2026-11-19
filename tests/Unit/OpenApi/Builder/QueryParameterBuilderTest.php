<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor;
use SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor;
use SineMacula\ApiToolkit\Query\PageSize;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\CappedPageUserResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

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
    /** @var string The grammar the filter parameter of every index states */
    private const string FILTER_GRAMMAR = 'Filters: a JSON object, URL-encoded under the one key, e.g. filters={"status":{"$eq":"active"}}. '
        . 'The entries of a document AND together. A field takes a value, matched for equality, or an object of operators. '
        . '$and and $or take a non-empty list of filter documents, joined by AND or OR, '
        . 'e.g. filters={"status":"active","$or":[{"role":"admin"},{"role":"owner"}]}. '
        . 'A traversable relation key takes a filter document for the related resource. '
        . '$has and $hasnt take a relation name, a list of names, or relation names keyed to filter documents. '
        . '$scope invokes the named scopes the resource declares: a scope name, a list of names, or names keyed to objects of arguments, '
        . 'e.g. filters={"$scope":{"near":{"lat":51.5,"lng":-0.12,"km":5}}}. '
        . 'The schema lists the fields, operators and values this resource accepts; a date-time is sent in the form the field emits.';

    /**
     * Test that the shared query-parameter set is emitted, without the filters
     * and order each index states for its own resource.
     *
     * @return void
     */
    public function testEmitsTheFullSharedParameterSet(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertSame(['Fields', 'Search', 'Limit', 'Page', 'Cursor', 'Pagination', 'Aggregates', 'Trashed'], array_keys($parameters));
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
        self::assertSame('search', $parameters['Search']['name']);
        self::assertSame('limit', $parameters['Limit']['name']);
        self::assertSame('page', $parameters['Page']['name']);
        self::assertSame('cursor', $parameters['Cursor']['name']);
        self::assertSame('pagination', $parameters['Pagination']['name']);
        self::assertSame('aggregates', $parameters['Aggregates']['name']);
        self::assertSame('trashed', $parameters['Trashed']['name']);
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
     * Test that the shared page size states its ceiling and the size an omitted
     * limit is served at.
     *
     * @return void
     */
    public function testTheSharedLimitCarriesTheCeilingAndTheDefault(): void
    {
        self::assertSame(
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            $this->makeBuilder()->build()['Limit']['schema'],
        );
    }

    /**
     * Test that a disabled page-size ceiling leaves the schema unbounded.
     *
     * @return void
     */
    public function testDisabledPageSizeCeilingLeavesTheLimitUnbounded(): void
    {
        self::assertSame(
            ['type' => 'integer', 'minimum' => 1, 'default' => 50],
            $this->makeBuilder(0)->build()['Limit']['schema'],
        );
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
        self::assertSame(
            'Pagination mode: set to cursor to switch from the default length-aware pagination to cursor pagination. Any other non-empty value is rejected.',
            $pagination['description'],
        );
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
     * Test that the sparse-fieldset description names the type keys, the
     * accepted values and what an unkeyed type returns.
     *
     * @return void
     */
    public function testFieldsDescriptionNamesTheTypeKeysValuesAndDefaultFields(): void
    {
        self::assertSame(
            'Sparse fieldsets keyed by resource _type, e.g. fields[users]=id,name. Each value is a comma-separated '
            . 'list of that schema\'s property names, with aggregates selecting its aggregates payload, or :all to '
            . 'select every field. Unknown types and names are rejected. A type with no key selects its schema\'s '
            . 'x-default-fields, unless a parent relation pins its fields. A selected property may still be left out; '
            . 'only the required ones are always returned.',
            $this->makeBuilder()->build()['Fields']['description'],
        );
    }

    /**
     * Test that every deep-object parameter uses the exploded deepObject style.
     *
     * @return void
     */
    public function testDeepObjectParametersCarryDeepObjectExplodedStyle(): void
    {
        $parameters = $this->makeBuilder()->build();

        foreach (['Fields', 'Aggregates'] as $name) {
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

        self::assertIsString($parameters['Search']['description']);
        self::assertStringContainsString('search=John Smith', $parameters['Search']['description']);
        self::assertSame(
            'Relation aggregates: request the keys of the resource\'s aggregates object per resource type, e.g. aggregates[users]=posts_count. '
            . 'Requesting any aggregate replaces the defaults of that type. A type\'s aggregates apply wherever it renders, at the root or '
            . 'embedded, when its fields select aggregates, except under a relation whose resource type varies per record. '
            . 'Unknown resource types and names are rejected.',
            $parameters['Aggregates']['description'],
        );
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
     * Test that the order description states its directions, tiebreaker,
     * relevance key and aggregate and relation keys.
     *
     * @return void
     */
    public function testOrderDescriptionStatesItsDirectionsTiebreakerAndKeyForms(): void
    {
        self::assertSame(
            'Ordering: a comma-separated list of keys, each optionally suffixed with :asc or :desc in any case, e.g. order=name,created_at:desc. '
            . 'Any other direction, or a repeated key, is rejected. The key is appended last when the order lacks it, so tied rows never repeat or go missing '
            . 'between pages. Random order and columns that admit null cannot order a cursor-paginated read. order=relevance ranks rows by how '
            . 'closely they match search: the whole value, then the start, then the start of a word, then anywhere; it needs search and offset '
            . 'pagination. A sortable aggregate orders by aggregates.<key>, e.g. order=aggregates.posts_count:desc, and a sortable to-one '
            . 'relation by a sortable column of the resource it renders, e.g. order=customer.name; neither can order a cursor-paginated read, '
            . 'and a NULL value sorts where the database places NULL.',
            $this->parameterNamed('order', $this->indexOver($this->surface([$this->sortable('name')])))['description'],
        );
    }

    /**
     * Test that the cursor description states when a token is rejected.
     *
     * @return void
     */
    public function testCursorDescriptionStatesWhenATokenIsRejected(): void
    {
        self::assertSame(
            'Opaque cursor token for cursor pagination. A token that does not decode, or does not match the requested order, is rejected; '
            . 'restart from the first page.',
            $this->makeBuilder()->build()['Cursor']['description'],
        );
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
     * Test that the relation-aggregates parameter describes a map of key lists
     * per resource type.
     *
     * @return void
     */
    public function testDeepObjectSchemasDescribeNestedStringMaps(): void
    {
        $parameters = $this->makeBuilder()->build();

        self::assertSame(
            ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            $parameters['Aggregates']['schema'],
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
     * Test that the visibility description states the default, the opt-in, the
     * relation cascade and the refusal.
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
        self::assertStringContainsString('An included relation follows it where its resource opts in and otherwise stays live.', $description);
        self::assertStringEndsWith(
            'A resource that has not opted in to exposing its soft-deleted records, or a caller it does not permit, gets a 422.',
            $description,
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
            ['Fields', 'Aggregates', 'Trashed'],
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
            ['Fields', 'Aggregates', 'Limit', 'Page', 'Cursor', 'Pagination'],
            $this->referencedNames('index', User::class),
        );

        self::assertSame(
            ['Fields', 'Aggregates'],
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
        self::assertSame(['Fields', 'Aggregates'], $this->referencedNames('store', Article::class));
        self::assertSame(['Fields', 'Aggregates'], $this->referencedNames('update', Article::class));
    }

    /**
     * Test that a store and an update accept only resource shaping.
     *
     * @return void
     */
    public function testWriteActionsAcceptShapingOnly(): void
    {
        $expected = ['Fields', 'Aggregates'];

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
     * Test that an index states its resource's filters and order inline, and
     * references search where a field is searchable, in operation order.
     *
     * @return void
     */
    public function testAnIndexTakesItsResourcesFiltersSearchAndOrderInOperationOrder(): void
    {
        $surface = $this->surface([$this->filterable('name', strategy: SearchStrategy::PREFIX), $this->sortable('created_at')]);

        self::assertSame(
            ['Fields', 'Aggregates', 'filters', 'Search', 'order', 'Limit', 'Page', 'Cursor', 'Pagination', 'Trashed'],
            $this->namesIn($this->makeBuilder(surface: $surface)->referencesFor('index', Article::class, UserResource::class)),
        );
    }

    /**
     * Test that an index whose resource has no query surface takes no filters,
     * search or order.
     *
     * @return void
     */
    public function testAnIndexWithoutAQuerySurfaceTakesNoFiltersSearchOrOrder(): void
    {
        self::assertSame(
            ['Fields', 'Aggregates', 'Limit', 'Page', 'Cursor', 'Pagination'],
            $this->namesIn($this->makeBuilder()->referencesFor('index', User::class, UserResource::class)),
        );
    }

    /**
     * Test that search is referenced only where a field is searchable.
     *
     * @return void
     */
    public function testSearchIsReferencedOnlyWhereAFieldIsSearchable(): void
    {
        $searched = new QueryColumnDescriptor('name', 'name', strategy: SearchStrategy::EXACT);

        self::assertNotContains('Search', $this->namesIn($this->indexOver($this->surface([$this->filterable('name')]))));
        self::assertContains(['$ref' => '#/components/parameters/Search'], $this->indexOver($this->surface([$searched])));
    }

    /**
     * Test that the filter parameter carries its resource's filter document as
     * JSON content, describing the grammar and each enabled cap.
     *
     * @return void
     */
    public function testTheFiltersParameterCarriesTheResourcesDocumentAsJsonContent(): void
    {
        $limits = [QueryCostLimits::MAX_BYTES => 4096, QueryCostLimits::MAX_PARSE_DEPTH => 8, QueryCostLimits::MAX_DEPTH => 2, QueryCostLimits::MAX_NODES => 50];
        $caps   = ' A document is held to at most 4096 bytes encoded, 8 levels of JSON nesting, 2 levels of groups and relations, 50 keys in all.';

        self::assertSame([
            'name'        => 'filters',
            'in'          => 'query',
            'required'    => false,
            'description' => self::FILTER_GRAMMAR . $caps,
            'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/UserFilters']]],
        ], $this->parameterNamed('filters', $this->indexUnder($limits, $this->surface([$this->filterable('name')]))));
    }

    /**
     * Test that the filter description states only the enabled caps, and none
     * when every cap is disabled.
     *
     * @return void
     */
    public function testTheFiltersDescriptionStatesOnlyTheEnabledCaps(): void
    {
        $surface = $this->surface([$this->filterable('name')]);

        $partial = $this->indexUnder([QueryCostLimits::MAX_DEPTH => 3, QueryCostLimits::MAX_NODES => 0], $surface);
        $none    = $this->indexUnder($this->caps(0), $surface);

        self::assertSame(self::FILTER_GRAMMAR . ' A document is held to at most 3 levels of groups and relations.', $this->parameterNamed('filters', $partial)['description']);
        self::assertSame(self::FILTER_GRAMMAR, $this->parameterNamed('filters', $none)['description']);
    }

    /**
     * Test that the filter parameter is offered wherever a filter may name a
     * column, a relation or a scope, and left out otherwise.
     *
     * @return void
     */
    public function testTheFiltersParameterIsOfferedWhereAFilterMayNameAnything(): void
    {
        self::assertContains('filters', $this->namesIn($this->indexOver($this->surface(relations: [new QueryRelationDescriptor('posts', PostResource::class)]))));
        self::assertContains('filters', $this->namesIn($this->indexOver($this->surface(scopes: [new QueryScopeDescriptor('upcoming')]))));
        self::assertNotContains('filters', $this->namesIn($this->indexOver($this->surface([$this->sortable('name')]))));
    }

    /**
     * Test that the order lists each sortable key once, bare and with each
     * direction, as a comma-separated list capped at the order key cap.
     *
     * @return void
     */
    public function testTheOrderListsEachSortableKeyWithEachDirection(): void
    {
        $surface = $this->surface([$this->sortable('name'), $this->sortable('name'), $this->filterable('email'), $this->sortable('aggregates.posts_count')]);
        $order   = $this->parameterNamed('order', $this->indexUnder([QueryCostLimits::MAX_ORDER_KEYS => 2], $surface));

        self::assertSame([
            'type'     => 'array',
            'items'    => ['type' => 'string', 'enum' => ['name', 'name:asc', 'name:desc', 'aggregates.posts_count', 'aggregates.posts_count:asc', 'aggregates.posts_count:desc']],
            'maxItems' => 2,
        ], $order['schema']);
        self::assertSame(['order', 'query', false, 'form', false], [$order['name'], $order['in'], $order['required'], $order['style'], $order['explode']]);
    }

    /**
     * Test that a disabled or unnamed order key cap leaves the order unbounded.
     *
     * @return void
     */
    public function testADisabledOrderKeyCapLeavesTheOrderUnbounded(): void
    {
        foreach ([$this->caps(0), []] as $limits) {

            $order = $this->parameterNamed('order', $this->indexUnder($limits, $this->surface([$this->sortable('name')])));

            self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['name', 'name:asc', 'name:desc']]], $order['schema']);
        }
    }

    /**
     * Test that relevance is listed, ascending only, where a field is
     * searchable, and a column named like it is read as relevance.
     *
     * @return void
     */
    public function testRelevanceIsListedAscendingOnlyWhereAFieldIsSearchable(): void
    {
        $searchable = $this->surface([$this->sortable('relevance'), new QueryColumnDescriptor('name', 'name', strategy: SearchStrategy::SUBSTRING)]);

        self::assertSame(['relevance', 'relevance:asc'], $this->orderKeysOf($searchable));
        self::assertSame([], $this->orderKeysOf($this->surface([$this->sortable('relevance')])));
    }

    /**
     * Test that random is listed only while random order is enabled, and a
     * column named like it is then read as random order.
     *
     * @return void
     */
    public function testRandomIsListedOnlyWhileEnabledAndShadowsAColumnNamedLikeIt(): void
    {
        $surface = $this->surface([$this->sortable('random'), $this->sortable('name')]);

        self::assertSame(['random', 'random:asc', 'random:desc', 'name', 'name:asc', 'name:desc'], $this->orderKeysOf($surface));

        Config::set('api-toolkit.repositories.allow_random_order', true);

        self::assertSame(['name', 'name:asc', 'name:desc', 'random'], $this->orderKeysOf($surface));
    }

    /**
     * Test that the order is left out where no key is sortable.
     *
     * @return void
     */
    public function testTheOrderIsLeftOutWhereNoKeyIsSortable(): void
    {
        self::assertNotContains('order', $this->namesIn($this->indexOver($this->surface([$this->filterable('name')]))));
    }

    /**
     * Test that only an index takes its resource's filters, search and order.
     *
     * @return void
     */
    public function testOnlyAnIndexTakesItsResourcesFiltersAndOrder(): void
    {
        $builder = $this->makeBuilder(surface: $this->surface([$this->filterable('name', strategy: SearchStrategy::PREFIX), $this->sortable('name')]));

        foreach (['show', 'store', 'update', 'destroy'] as $action) {
            self::assertSame($this->makeBuilder()->referencesFor($action, User::class), $builder->referencesFor($action, User::class, UserResource::class), $action);
        }
    }

    /**
     * Test that an index over a resource with its own page size bounds takes an
     * inline limit stating them, in the shared one's place.
     *
     * @return void
     */
    public function testIndexOverALoweredResourceInlinesItsLimit(): void
    {
        $builder    = $this->makeBuilder();
        $references = $builder->referencesFor('index', User::class, CappedPageUserResource::class);

        self::assertSame(
            [...$builder->build()['Limit'], 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2, 'default' => 2]],
            $references[2],
        );

        self::assertSame(
            [...$builder->referencesFor('index', User::class), 2 => $references[2]],
            $references,
        );
    }

    /**
     * Test that a resource keeping the configured page size bounds, and any
     * action but an index, reference the shared limit.
     *
     * @return void
     */
    public function testOnlyAnIndexOverALoweredResourceInlinesItsLimit(): void
    {
        $builder = $this->makeBuilder();

        self::assertSame($builder->referencesFor('index', User::class), $builder->referencesFor('index', User::class, UserResource::class));
        self::assertSame($builder->referencesFor('show', User::class), $builder->referencesFor('show', User::class, CappedPageUserResource::class));
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
     * Provide each query cost cap the catalogue reports.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function capProvider(): iterable
    {
        foreach (self::capNames() as $cap) {
            yield $cap => [$cap];
        }
    }

    /**
     * Test that an index raises the query cost refusal beside the validation
     * failure while any one cap is enabled.
     *
     * @param  string  $cap
     * @return void
     */
    #[DataProvider('capProvider')]
    public function testIndexRaisesTheQueryCostRefusalWhileAnyCapIsEnabled(string $cap): void
    {
        self::assertSame(
            [ValidationException::class, QueryTooExpensiveException::class],
            $this->builderReporting([...$this->caps(-1), $cap => 1])->exceptionsFor('index'),
        );
    }

    /**
     * Test that an index raises only the validation failure while every cap is
     * disabled.
     *
     * @return void
     */
    public function testIndexRaisesOnlyTheValidationFailureWhileEveryCapIsDisabled(): void
    {
        self::assertSame([ValidationException::class], $this->builderReporting($this->caps(0))->exceptionsFor('index'));
        self::assertSame([ValidationException::class], $this->builderReporting($this->caps(-1))->exceptionsFor('index'));
    }

    /**
     * Test that an index over a resource with its own ceiling raises the query
     * cost refusal while every configured cap is disabled.
     *
     * @return void
     */
    public function testIndexOverALoweredResourceRaisesTheQueryCostRefusal(): void
    {
        self::assertSame(
            [ValidationException::class, QueryTooExpensiveException::class],
            $this->builderReporting($this->caps(0))->exceptionsFor('index', User::class, CappedPageUserResource::class),
        );

        self::assertSame(
            [ValidationException::class],
            $this->builderReporting($this->caps(0))->exceptionsFor('index', User::class, UserResource::class),
        );
    }

    /**
     * Test that the actions taking no selection parameter raise only the
     * validation failure.
     *
     * @return void
     */
    public function testActionsWithoutSelectionParametersRaiseOnlyTheValidationFailure(): void
    {
        $builder = $this->builderReporting($this->caps(1));

        self::assertSame([ValidationException::class], $builder->exceptionsFor('show'));
        self::assertSame([ValidationException::class], $builder->exceptionsFor('show', Article::class));
        self::assertSame([ValidationException::class], $builder->exceptionsFor('store'));
        self::assertSame([ValidationException::class], $builder->exceptionsFor('update'));
    }

    /**
     * Test that an action raises an exception exactly when it references a
     * parameter.
     *
     * @return void
     */
    public function testActionsRaiseExceptionsExactlyWhenTheyReferenceParameters(): void
    {
        $builder = $this->makeBuilder();

        self::assertSame([], $builder->exceptionsFor('destroy'));
        self::assertSame([], $builder->exceptionsFor('report'));

        foreach (['index', 'show', 'store', 'update', 'destroy', 'report'] as $action) {
            self::assertSame($builder->referencesFor($action) === [], $builder->exceptionsFor($action) === [], $action);
        }
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
        $catalogue->method('getQueryLimits')->willReturn([QueryParameterValidator::MAX_LIMIT => 100]);
        $catalogue->method('getPageSize')->willReturn(new PageSize(100, 50));
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
     * List the parameter names the given action takes, as namesIn() does.
     *
     * @param  string  $action
     * @param  class-string|null  $modelClass
     * @return list<string>
     */
    private function referencedNames(string $action, ?string $modelClass = null): array
    {
        return $this->namesIn($this->makeBuilder()->referencesFor($action, $modelClass));
    }

    /**
     * Name each parameter: a shared one by its component, an inline one by the
     * query parameter it carries.
     *
     * @param  list<array<string, mixed>>  $parameters
     * @return list<string>
     */
    private function namesIn(array $parameters): array
    {
        return array_map(static function (array $parameter): string {

            $name = $parameter['$ref'] ?? $parameter['name'] ?? null;

            self::assertIsString($name);

            return str_replace('#/components/parameters/', '', $name);
        }, $parameters);
    }

    /**
     * Find the inline parameter carrying the given query parameter.
     *
     * @param  string  $name
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, mixed>
     */
    private function parameterNamed(string $name, array $parameters): array
    {
        foreach ($parameters as $parameter) {
            if (($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail(sprintf('No "%s" parameter is listed.', $name));
    }

    /**
     * List the parameters an index over the given surface takes.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $surface
     * @return list<array<string, mixed>>
     */
    private function indexOver(QuerySurfaceDescriptor $surface): array
    {
        return $this->makeBuilder(surface: $surface)->referencesFor('index', User::class, UserResource::class);
    }

    /**
     * List the parameters an index over the given surface takes under the given
     * bounds.
     *
     * @param  array<string, int>  $limits
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $surface
     * @return list<array<string, mixed>>
     */
    private function indexUnder(array $limits, QuerySurfaceDescriptor $surface): array
    {
        return $this->builderReporting($limits, surface: $surface)->referencesFor('index', User::class, UserResource::class);
    }

    /**
     * List the order keys an index over the given surface accepts.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor  $surface
     * @return list<string>
     */
    private function orderKeysOf(QuerySurfaceDescriptor $surface): array
    {
        foreach ($this->indexOver($surface) as $parameter) {
            if (($parameter['name'] ?? null) === 'order') {
                /** @var list<string> */
                return $parameter['schema']['items']['enum'];
            }
        }

        return [];
    }

    /**
     * Build a surface of the user resource.
     *
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor>  $columns
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryRelationDescriptor>  $relations
     * @param  list<\SineMacula\ApiToolkit\OpenApi\Metadata\QueryScopeDescriptor>  $scopes
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor
     */
    private function surface(array $columns = [], array $relations = [], array $scopes = []): QuerySurfaceDescriptor
    {
        return new QuerySurfaceDescriptor(UserResource::class, $columns, $relations, $scopes, models: [User::class]);
    }

    /**
     * Build a column a filter may name.
     *
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy|null  $strategy
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor
     */
    private function filterable(string $column, ?SearchStrategy $strategy = null): QueryColumnDescriptor
    {
        return new QueryColumnDescriptor($column, $column, Capability::EXACT, ['$eq'], strategy: $strategy);
    }

    /**
     * Build a column an order may name.
     *
     * @param  string  $column
     * @return \SineMacula\ApiToolkit\OpenApi\Metadata\QueryColumnDescriptor
     */
    private function sortable(string $column): QueryColumnDescriptor
    {
        return new QueryColumnDescriptor($column, $column, sortable: true);
    }

    /**
     * Report every query cost cap at the given value.
     *
     * @param  int  $value
     * @return array<string, int>
     */
    private function caps(int $value): array
    {
        return array_fill_keys(self::capNames(), $value);
    }

    /**
     * List the name of every query cost cap the catalogue reports.
     *
     * @return list<string>
     */
    private static function capNames(): array
    {
        return [...QueryCostLimits::NAMES, QueryParameterValidator::MAX_LIMIT];
    }

    /**
     * Build a QueryParameterBuilder with the default operators and ceiling.
     *
     * @param  int  $ceiling
     * @param  int  $longest
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor|null  $surface
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder
     */
    private function makeBuilder(int $ceiling = 100, int $longest = 128, ?QuerySurfaceDescriptor $surface = null): QueryParameterBuilder
    {
        return $this->builderReporting([QueryParameterValidator::MAX_LIMIT => $ceiling], $longest, $surface);
    }

    /**
     * Build a QueryParameterBuilder over a catalogue with the given bounds and
     * resource surface, under which the paged fixture resource lowers the page
     * size to two.
     *
     * @param  array<string, int>  $limits
     * @param  int  $longest
     * @param  \SineMacula\ApiToolkit\OpenApi\Metadata\QuerySurfaceDescriptor|null  $surface
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder
     */
    private function builderReporting(array $limits, int $longest = 128, ?QuerySurfaceDescriptor $surface = null): QueryParameterBuilder
    {
        $configured = new PageSize($limits[QueryParameterValidator::MAX_LIMIT] ?? 0, 50);

        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getQueryLimits')->willReturn($limits);
        $catalogue->method('getQuerySurface')->willReturn($surface);
        $catalogue->method('getPageSize')->willReturnCallback(
            static fn (?string $resource): PageSize => $resource === CappedPageUserResource::class ? new PageSize(2, 2) : $configured,
        );
        $catalogue->method('getSearchBounds')->willReturn([
            SearchTerm::MIN_WORD_LENGTH_KEY => 3,
            SearchTerm::MAX_LENGTH_KEY      => $longest,
            SearchTerm::MAX_WORDS_KEY       => 10,
        ]);

        return new QueryParameterBuilder($catalogue);
    }
}
