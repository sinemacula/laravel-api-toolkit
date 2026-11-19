<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Opis\JsonSchema\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\OpenApi\Builder\FilterSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\Repositories\Criteria\CallerAdmission;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterApplier;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\RelationTraversal;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Search\SearchPlan;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Models\ManualUser;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathArticleController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathLogController;
use Tests\Fixtures\Repositories\ArticleRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\AliasedSurfaceArticleResource;
use Tests\Fixtures\Resources\CapabilitySpectrumLogResource;
use Tests\Fixtures\Resources\GuardedQueryableUserResource;
use Tests\Fixtures\Resources\ManualUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\SearchableFilterableUserResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Contract tests binding the emitted query surface to the live package.
 *
 * Agreement is asserted at the gate, which decides every filter predicate, so
 * the whole matrix is walked without SQL a development connection cannot run.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceSchemaBuilder::class)]
#[CoversClass(QueryParameterBuilder::class)]
#[CoversClass(FilterSchemaBuilder::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(Capability::class)]
final class OpenApiQuerySurfaceContractTest extends TestCase
{
    use RegistersApiExceptionHandler, ValidatesOpenApiDocuments;

    /** @var string The pattern matching an operator-shaped token in any string the document carries. */
    private const string TOKEN_PATTERN = '/\$[A-Za-z][A-Za-z0-9_]*/';

    /** @var string The property extension naming what a field may be queried by. */
    private const string SURFACE_KEY = 'x-query-surface';

    /** @var string A token the application registers beside the shipped ones. */
    private const string STARTS_WITH = '$starts';

    /** @var string|null The temporary docs directory to clean up. */
    private ?string $docsDir = null;

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        Config::set('api-toolkit.resources.resource_map', $this->resourceMap());
    }

    /**
     * Tear down each test, removing any temporary docs directory.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->docsDir !== null) {
            $this->removeDirectory($this->docsDir);
        }

        $this->docsDir = null;

        parent::tearDown();
    }

    /**
     * Test that every operator token in the document is in the live vocabulary.
     *
     * @return void
     */
    public function testEveryOperatorTokenInTheDocumentIsInTheLiveVocabulary(): void
    {
        $this->registerRoutes();
        $this->assembleManual();

        $document    = $this->export();
        $description = $document['info']['description'] ?? null;

        self::assertIsString($description);
        self::assertStringContainsString('# Advanced Querying', $description);
        self::assertStringContainsString('# Query Surface Reference', $description);

        $tokens = $this->operatorTokensIn($document);

        self::assertNotEmpty($tokens);
        self::assertSame([], array_values(array_diff($tokens, $this->vocabulary())));
    }

    /**
     * Test that the scan reports a token the registry no longer holds.
     *
     * The generated halves are narrowed against the registry, so only
     * hand-written prose survives a removal, which is the drift the scan exists
     * to catch.
     *
     * @return void
     */
    public function testATokenTheRegistryNoLongerHoldsIsReportedByTheScan(): void
    {
        $this->registerRoutes();

        $this->registry()->remove('$in');

        $this->assembleManual();

        self::assertNotContains('$in', $this->vocabulary());
        self::assertContains('$in', $this->operatorTokensIn($this->export()));
    }

    /**
     * Test that the scan reads tokens from keys as well as values, other than
     * the JSON Schema reference keyword.
     *
     * @return void
     */
    public function testTheScanReadsKeysOtherThanTheReferenceKeyword(): void
    {
        self::assertSame(['$gone', '$stale'], $this->operatorTokensIn(['properties' => ['$gone' => ['$ref' => '#/components/schemas/X'], 'name' => '$stale']]));
    }

    /**
     * Test that a dropped token leaves every column and every filter document.
     *
     * @return void
     */
    public function testATokenTheRegistryNoLongerHoldsIsGoneFromEveryPerColumnSurface(): void
    {
        $this->registerRoutes();

        $this->registry()->remove('$in');

        $surfaces = $this->advertisedSurfaces($this->export());

        self::assertNotEmpty($surfaces);

        foreach ($surfaces as $surface) {
            self::assertNotContains(
                '$in',
                $surface['operators'],
                sprintf('The document advertises "$in" on the "%s" key, which the registry no longer binds.', $surface['key']),
            );
        }

        foreach ($this->filterDocuments() as $resource => $document) {
            foreach ($this->filteredColumns($document) as $column => $tokens) {
                self::assertNotContains('$in', $tokens, sprintf('The %s filter document offers "$in" on "%s".', $resource, $column));
            }
        }
    }

    /**
     * Test that every column and token a filter document lists is admitted by
     * the gate, and every column and token the gate admits is listed.
     *
     * @return void
     */
    public function testEveryFilterDocumentListsExactlyTheColumnsAndTokensTheGateAdmits(): void
    {
        $this->registerStartsWith();

        $asserted = 0;

        foreach ($this->filterDocuments() as $modelClass => $document) {

            $gate     = $this->gateFor($modelClass);
            $compiled = SchemaCompiler::compile($this->resourceMap()[$modelClass]);
            $columns  = $this->filteredColumns($document);
            $expected = array_filter(array_map($this->registry()->tokensFor(...), $compiled->getFilterableColumns()));

            self::assertSame($expected, $columns, $modelClass);

            foreach ($columns as $column => $tokens) {
                foreach ($this->registry()->tokens() as $token) {
                    self::assertSame(in_array($token, $tokens, true), $this->isAdmitted($gate, $column, $token), sprintf('%s on "%s"', $token, $column));
                    $asserted++;
                }
            }
        }

        self::assertGreaterThan(0, $asserted);
    }

    /**
     * Test that every relation a filter document lists is one the gate lets a
     * filter descend through, and every such relation is listed.
     *
     * @return void
     */
    public function testEveryFilterDocumentListsExactlyTheRelationsTheGateAdmits(): void
    {
        $relations = 0;

        foreach ($this->filterDocuments() as $modelClass => $document) {

            $listed = array_keys(array_diff_key($document['properties'], $this->filteredColumns($document), array_flip(FilterApplier::STRUCTURAL_OPERATORS)));
            $gate   = $this->gateFor($modelClass);

            self::assertSame(SchemaCompiler::compile($this->resourceMap()[$modelClass])->getTraversableRelations(), $listed, $modelClass);

            foreach ($listed as $relation) {
                self::assertTrue($gate->isDeclaredRelation($relation), $relation);
                $relations++;
            }
        }

        self::assertGreaterThan(0, $relations);
    }

    /**
     * Test that every key an index's order lists passes the gate, and every key
     * the gate sorts by is listed.
     *
     * @return void
     */
    public function testEveryOrderListsExactlyTheKeysTheGateSortsBy(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $listed = 0;

        foreach ($this->resourceMap() as $modelClass => $resourceClass) {

            $compiled = SchemaCompiler::compile($resourceClass);
            $keys     = array_values(array_unique(array_map(static fn (string $key): string => explode(':', $key)[0], $this->orderKeysOf($modelClass, $resourceClass))));
            $sortable = [...$compiled->getSortableColumns(), ...array_keys($compiled->getSortableMetrics()), ...$this->relationSortKeys($compiled)];

            self::assertSame([], array_values(array_diff($sortable, $keys)), $resourceClass);

            foreach (array_diff($keys, ['random', 'relevance']) as $key) {
                $this->gateFor($modelClass)->guardSort($key);
                $listed++;
            }

            self::assertContains('random', $keys);
            self::assertSame(!SearchPlan::for($resourceClass)->isEmpty(), in_array('relevance', $keys, true), $resourceClass);
        }

        self::assertGreaterThan(0, $listed);
    }

    /**
     * Test that an index offers search exactly where its resource has a search
     * plan.
     *
     * @return void
     */
    public function testAnIndexOffersSearchExactlyWhereItsResourceHasASearchPlan(): void
    {
        $map = [...$this->resourceMap(), User::class => SearchableFilterableUserResource::class];

        foreach ($map as $modelClass => $resourceClass) {
            self::assertSame(
                !SearchPlan::for($resourceClass)->isEmpty(),
                in_array('Search', $this->parametersFor('index', $modelClass, $resourceClass), true),
                $resourceClass,
            );
        }
    }

    /**
     * Test that a document over the node cap is refused though its schema
     * accepts it.
     *
     * @return void
     */
    public function testADocumentOverTheNodeCapIsRefusedThoughItsSchemaAcceptsIt(): void
    {
        Config::set('api-toolkit.query_cost.max_nodes', 2);

        $this->registerApiExceptionHandler();
        $this->registerQueryRoute();

        $filters = ['name' => 'Alice', 'email' => 'alice@example.com', 'id' => 1];

        $this->assertFilterDocumentAccepts(UserResource::class, $filters);

        $refused = $this->filtered($filters);

        $refused->assertStatus(422);
        $refused->assertJsonPath('error.code', ErrorCode::QUERY_TOO_EXPENSIVE->getCode());
    }

    /**
     * Test that a key only some callers may filter by is described as such, and
     * refused to a caller it does not admit.
     *
     * @return void
     */
    public function testAConditionalKeyIsDescribedAndRefusedToACallerItDoesNotAdmit(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => GuardedQueryableUserResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/guarded-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(GuardedQueryableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, GuardedQueryableUserResource::class);
        });

        $filters = ['email' => 'alice@example.com'];

        $this->assertFilterDocumentAccepts(GuardedQueryableUserResource::class, $filters);

        self::assertSame(
            'Refused, as an undeclared key is, for callers the resource does not admit.',
            $this->builtFilterDocuments()['GuardedQueryableUserFilters']['properties']['email']['description'] ?? null,
        );

        $this->getJson('/guarded-users?filters=' . urlencode((string) json_encode($filters)))->assertStatus(422);
    }

    /**
     * Test that an integer written with a fraction is refused though JSON
     * Schema counts it as an integer.
     *
     * @return void
     */
    public function testAnIntegerWrittenWithAFractionIsRefusedThoughItsSchemaAcceptsIt(): void
    {
        $this->registerApiExceptionHandler();
        $this->registerQueryRoute();

        $result = $this->validateAgainstExported($this->documentOf(UserResource::class), '/components/schemas/UserFilters', json_decode('{"id":1.0}'));

        self::assertTrue($result->isValid());

        $this->getJson('/queried-users?filters=' . urlencode('{"id":1.0}'))->assertStatus(422);
    }

    /**
     * Test that random order, which the order lists, is refused under cursor
     * pagination.
     *
     * @return void
     */
    public function testRandomOrderIsRefusedUnderCursorPagination(): void
    {
        Config::set('api-toolkit.repositories.allow_random_order', true);

        $this->registerApiExceptionHandler();
        $this->registerQueryRoute();

        self::assertContains('random', $this->orderKeysOf(User::class, UserResource::class));

        $this->getJson('/queried-users?order=random')->assertOk();
        $this->getJson('/queried-users?order=random&pagination=cursor')->assertStatus(422);
    }

    /**
     * Test that a column left with no dispatchable operator is not advertised.
     *
     * @return void
     */
    public function testAColumnLeftWithNoDispatchableOperatorIsNoLongerAdvertised(): void
    {
        $this->registerRoutes();

        $documented = array_column($this->advertisedSurfaces($this->export()), 'capability');

        self::assertContains(Capability::DOCUMENT->value, $documented);

        $this->registry()->remove('$contains');

        $narrowed = array_column($this->advertisedSurfaces($this->export()), 'capability');

        self::assertNotContains(Capability::DOCUMENT->value, $narrowed);
    }

    /**
     * Test that every advertised operator, a registered one included, is
     * permitted by the gate.
     *
     * @return void
     */
    public function testEveryAdvertisedOperatorIsPermittedByTheGate(): void
    {
        $this->registerRoutes();
        $this->registerStartsWith();

        $asserted = [];

        foreach ($this->advertisedSurfaces($this->export()) as $surface) {

            $gate = $this->gateFor($surface['model']);

            foreach ($surface['operators'] as $operator) {

                try {
                    $gate->guardFilterOperator($surface['key'], $operator);
                } catch (ValidationException $exception) {
                    self::fail(sprintf(
                        'The document advertises "%s" on the "%s" key, which the gate refuses: %s',
                        $operator,
                        $surface['key'],
                        (string) json_encode($exception->errors()),
                    ));
                }

                $asserted[$operator] = true;
            }
        }

        self::assertArrayHasKey(self::STARTS_WITH, $asserted);
    }

    /**
     * Test that every registered operator a surface omits, a registered one
     * included, is refused by the gate.
     *
     * @return void
     */
    public function testEveryOmittedOperatorIsRefusedByTheGate(): void
    {
        $this->registerRoutes();
        $this->registerStartsWith();

        $asserted = [];

        foreach ($this->advertisedSurfaces($this->export()) as $surface) {

            $gate     = $this->gateFor($surface['model']);
            $accepted = implode(', ', $surface['operators']);

            foreach (array_diff($this->registry()->tokens(), $surface['operators']) as $operator) {

                $message = sprintf(
                    'The "%s" operator is not permitted on the "%s" key for this resource, which accepts %s.',
                    $operator,
                    $surface['key'],
                    $accepted,
                );

                try {
                    $gate->guardFilterOperator($surface['key'], $operator);

                    self::fail(sprintf('The document omits "%s" from the "%s" key, which the gate permits.', $operator, $surface['key']));
                } catch (ValidationException $exception) {
                    self::assertSame(['filters.' . $surface['key'] . '.' . $operator => [$message]], $exception->errors());
                }

                $asserted[$operator] = true;
            }
        }

        self::assertArrayHasKey(self::STARTS_WITH, $asserted);
    }

    /**
     * Test that the documented surfaces cover every capability.
     *
     * @return void
     */
    public function testTheDocumentedSurfacesCoverEveryCapability(): void
    {
        $this->registerRoutes();

        $capabilities = array_unique(array_column($this->advertisedSurfaces($this->export()), 'capability'));
        $expected     = array_column(Capability::cases(), 'value');

        sort($capabilities);
        sort($expected);

        self::assertSame($expected, $capabilities);
    }

    /**
     * Test that a wire refusal names exactly the advertised operators.
     *
     * @return void
     */
    public function testTheRefusalOnTheWireNamesTheOperatorsTheDocumentAdvertises(): void
    {
        $this->registerApiExceptionHandler();
        $this->registerRoutes();
        $this->registerQueryRoute();

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        $advertised = $this->advertisedOperatorsFor($this->export(), User::class, 'name');

        $refused = $this->filtered(['name' => ['$gt' => 'A']]);

        $refused->assertStatus(422);
        $refused->assertJsonPath('error.code', ErrorCode::INVALID_INPUT->getCode());

        $meta = (array) $refused->json('error.meta');

        self::assertSame(
            ['The "$gt" operator is not permitted on the "name" key for this resource, which accepts ' . implode(', ', $advertised) . '.'],
            $meta['filters.name.$gt'] ?? [],
        );

        $answered = $this->filtered(['name' => ['$eq' => 'Alice']]);

        $answered->assertOk();
        $answered->assertJsonPath('data.0.name', 'Alice');
    }

    /**
     * Test that the documented longest term is the one the wire enforces.
     *
     * @return void
     */
    public function testTheDocumentedLongestTermIsTheOneTheWireEnforces(): void
    {
        // The dev engine cannot prove an index; the bound is enforced first
        Config::set('api-toolkit.search.unverified_connections', ['testing']);

        $this->registerApiExceptionHandler();
        $this->registerRoutes();
        $this->registerSearchRoute();

        $published = $this->export()['components']['parameters']['Search']['schema']['maxLength'] ?? null;

        self::assertSame(SearchTerm::maximumLength(), $published);
        self::assertIsInt($published);

        $accepted = $this->getJson('/searched-users?search=' . str_repeat('a', $published));

        $accepted->assertOk();

        $refused = $this->getJson('/searched-users?search=' . str_repeat('a', $published + 1));

        $refused->assertStatus(422);
        $refused->assertJsonPath('error.code', ErrorCode::INVALID_INPUT->getCode());

        self::assertSame(
            ['The search term may not be longer than ' . $published . ' characters.'],
            (array) (((array) $refused->json('error.meta'))['search'] ?? []),
        );
    }

    /**
     * Test that the documented surfaces reach a key that is not its property.
     *
     * @return void
     */
    public function testTheDocumentedSurfacesReachAKeyThatIsNotThePropertyItHangsOn(): void
    {
        $this->registerRoutes();

        $aliased = [];

        foreach ($this->advertisedSurfaces($this->export()) as $surface) {

            if ($surface['key'] === $surface['property']) {
                continue;
            }

            $aliased[$surface['property']] = $surface['key'];
        }

        self::assertSame(['permalink' => 'slug', 'state' => 'status'], $aliased);
    }

    /**
     * Test that an aliased field advertises its column, not its property.
     *
     * @return void
     */
    public function testTheAdvertisedKeysOfAnAliasedFieldAreItsColumnRatherThanItsProperty(): void
    {
        $this->registerRoutes();

        $document = $this->export();

        self::assertSame('slug', $this->advertisedKeyOn($document, AliasedSurfaceArticleResource::class, 'permalink', 'filter'));
        self::assertSame('slug', $this->advertisedKeyOn($document, AliasedSurfaceArticleResource::class, 'permalink', 'sort'));
        self::assertArrayNotHasKey('slug', $this->propertiesOf($document, AliasedSurfaceArticleResource::class));
    }

    /**
     * Test that an aliased field's advertised key is the key a filter sends.
     *
     * @return void
     */
    public function testTheAdvertisedKeyOfAnAliasedFieldIsTheKeyAFilterSendsItUnder(): void
    {
        $this->registerApiExceptionHandler();
        $this->registerRoutes();
        $this->registerAliasedArticleRoute();

        $this->seedArticles();

        $key = $this->advertisedKeyOn($this->export(), AliasedSurfaceArticleResource::class, 'permalink', 'filter');

        $answered = $this->filteredArticles([$key => ['$eq' => 'second-article']]);

        $answered->assertOk();
        $answered->assertJsonCount(1, 'data');
        $answered->assertJsonPath('data.0.permalink', 'second-article');

        self::assertArrayNotHasKey('slug', (array) $answered->json('data.0'));

        $refused = $this->filteredArticles(['permalink' => ['$eq' => 'second-article']]);

        $refused->assertStatus(422);
        $refused->assertJsonPath('error.code', ErrorCode::INVALID_INPUT->getCode());

        $meta = (array) $refused->json('error.meta');

        self::assertSame(
            ['The "permalink" key is not a permitted query parameter for this resource.'],
            $meta['filters.permalink'] ?? [],
        );
    }

    /**
     * Test that an aliased field's advertised key is the key an order names.
     *
     * @return void
     */
    public function testTheAdvertisedKeyOfAnAliasedFieldIsTheKeyAnOrderNamesItBy(): void
    {
        $this->registerApiExceptionHandler();
        $this->registerRoutes();
        $this->registerAliasedArticleRoute();

        $this->seedArticles();

        $key = $this->advertisedKeyOn($this->export(), AliasedSurfaceArticleResource::class, 'permalink', 'sort');

        $ordered = $this->getJson('/aliased-articles?order=' . $key . ':desc');

        $ordered->assertOk();

        self::assertSame(
            ['wide-article', 'second-article', 'first-article'],
            array_column((array) $ordered->json('data'), 'permalink'),
        );

        $refused = $this->getJson('/aliased-articles?order=permalink:desc');

        $refused->assertStatus(422);
        $refused->assertJsonPath('error.code', ErrorCode::INVALID_INPUT->getCode());

        $meta = (array) $refused->json('error.meta');

        self::assertSame(
            ['The "permalink" key is not a permitted query parameter for this resource.'],
            $meta['order.permalink'] ?? [],
        );
    }

    /**
     * Test that the manual tables every parameter the document defines.
     *
     * @return void
     */
    public function testTheManualTablesEveryParameterTheDocumentDefines(): void
    {
        $this->registerRoutes();

        $manual   = $this->manual();
        $document = $this->export();
        $shared   = array_keys($this->parameterNames($document, []));
        $inline   = array_values(array_diff($this->parametersFor('index', ManualUser::class, ManualUserResource::class), $shared));
        $names    = $this->parameterNames($document, $inline);
        $tabled   = $this->tabledParameterNames();

        self::assertSame(['filters', 'order'], $inline);

        self::assertNotEmpty($tabled);
        self::assertSame([], array_values(array_diff($tabled, array_values($names))));

        foreach ($names as $name) {
            self::assertStringContainsString(
                '`' . $name . '`',
                $manual,
                sprintf('The manual names no "%s" parameter, which the document defines.', $name),
            );
        }
    }

    /**
     * Test that the manual splits the grammar the way the operations do.
     *
     * @return void
     */
    public function testTheManualSplitsTheGrammarTheWayTheOperationsDo(): void
    {
        $this->registerRoutes();

        $names  = $this->parameterNames($this->export(), []);
        $tabled = $this->tabledParameterNames();

        $shaping = $this->queryNames($this->parametersFor('store', ManualUser::class, ManualUserResource::class), $names);

        self::assertSame($shaping, array_slice($tabled, 0, count($shaping)));

        // Selection grammar next, holding back the two cursor-paging parameters
        $index     = $this->queryNames($this->parametersFor('index', ManualUser::class, ManualUserResource::class), $names);
        $selection = array_values(array_diff($index, $shaping, ['cursor', 'pagination']));

        self::assertSame($selection, array_slice($tabled, count($shaping), count($selection)));
    }

    /**
     * Test that the manual operator table is the live vocabulary.
     *
     * @return void
     */
    public function testTheManualOperatorTableIsTheLiveVocabulary(): void
    {
        $tabled = array_map($this->unquote(...), $this->tabledRows('| Operator', 0));

        sort($tabled);

        $registered = $this->registry()->tokens();

        sort($registered);

        self::assertSame($registered, $tabled);
    }

    /**
     * Test that the manual capability matrix is the enum matrix, row for row.
     *
     * @return void
     */
    public function testTheManualCapabilityMatrixIsTheEnumMatrix(): void
    {
        $tabled = array_map(
            $this->tokensIn(...),
            $this->tabledRows('| The field is', 1),
        );

        $expected = array_map(
            static fn (Capability $case): array => $case->defaultOperators(),
            Capability::cases(),
        );

        self::assertSame($expected, $tabled);
    }

    /**
     * Test that the manual surface example lists its capability's operators.
     *
     * @return void
     */
    public function testTheManualSurfaceExampleListsTheOperatorsItsCapabilityAnswers(): void
    {
        $manual = $this->manual();

        self::assertSame(1, preg_match('/"capability": "(?<capability>[a-z]+)"/', $manual, $capability));
        self::assertSame(1, preg_match('/"operators": \[(?<operators>[^\]]+)\]/', $manual, $operators));

        $case = Capability::from($capability['capability']);

        self::assertSame($case->defaultOperators(), $this->tokensIn($operators['operators']));
    }

    /**
     * Remove a directory and everything beneath it.
     *
     * @param  string  $directory
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {

            if (is_dir($entry)) {
                $this->removeDirectory($entry);
                continue;
            }

            unlink($entry);
        }

        @rmdir($directory);
    }

    /**
     * Read the shipped querying manual.
     *
     * @return string
     */
    private function manual(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/resources/api-docs/20-advanced-querying.md');
    }

    /**
     * List the parameter names the manual tables, in the order it tables them.
     *
     * @return list<string>
     */
    private function tabledParameterNames(): array
    {
        return array_map($this->unquote(...), $this->tabledRows('| Parameter', 0));
    }

    /**
     * Read one column of every row of the manual table under the given header.
     *
     * @param  string  $header
     * @param  int  $column
     * @return list<string>
     */
    private function tabledRows(string $header, int $column): array
    {
        $lines = explode("\n", $this->manual());
        $rows  = [];
        $found = false;

        foreach ($lines as $line) {

            if (!$found) {
                $found = str_starts_with($line, $header);
                continue;
            }

            if (!str_starts_with($line, '|')) {
                break;
            }

            $cells = array_map('trim', array_slice(explode('|', $line), 1, -1));

            if (str_starts_with($cells[0], '---')) {
                continue;
            }

            $rows[] = $cells[$column];
        }

        self::assertNotEmpty($rows, sprintf('The manual carries no table headed "%s".', $header));

        return $rows;
    }

    /**
     * List the operator tokens a cell names, in the order it names them.
     *
     * @param  string  $cell
     * @return list<string>
     */
    private function tokensIn(string $cell): array
    {
        preg_match_all(self::TOKEN_PATTERN, $cell, $matches);

        self::assertNotEmpty($matches[0], sprintf('The cell "%s" names no operator token.', $cell));

        return $matches[0];
    }

    /**
     * Strip the code-span quoting from a table cell.
     *
     * @param  string  $cell
     * @return string
     */
    private function unquote(string $cell): string
    {
        return trim($cell, '`');
    }

    /**
     * Map each parameter component name to the query parameter name it carries,
     * with each inline parameter's name beside them.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $inline
     * @return array<string, string>
     */
    private function parameterNames(array $document, array $inline): array
    {
        /** @var array<string, array{name: string}> $parameters */
        $parameters = $document['components']['parameters'];

        return [...array_map(static fn (array $parameter): string => $parameter['name'], $parameters), ...array_combine($inline, $inline)];
    }

    /**
     * List the parameters an action over the resource takes: a shared one by
     * its component name, an inline one by the query parameter it carries.
     *
     * @param  string  $action
     * @param  class-string  $modelClass
     * @param  class-string  $resourceClass
     * @return list<string>
     */
    private function parametersFor(string $action, string $modelClass, string $resourceClass): array
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder $builder */
        $builder = $this->makeApplication()->make(QueryParameterBuilder::class);

        return array_map(static function (array $parameter): string {

            $name = $parameter['$ref'] ?? $parameter['name'] ?? null;

            self::assertIsString($name);

            return str_replace('#/components/parameters/', '', $name);
        }, $builder->referencesFor($action, $modelClass, $resourceClass));
    }

    /**
     * Name each listed parameter by the query parameter it carries.
     *
     * @param  list<string>  $parameters
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private function queryNames(array $parameters, array $names): array
    {
        return array_map(static fn (string $parameter): string => $names[$parameter] ?? $parameter, $parameters);
    }

    /**
     * List the order keys an index over the resource accepts.
     *
     * @param  class-string  $modelClass
     * @param  class-string  $resourceClass
     * @return list<string>
     */
    private function orderKeysOf(string $modelClass, string $resourceClass): array
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder $builder */
        $builder = $this->makeApplication()->make(QueryParameterBuilder::class);

        foreach ($builder->referencesFor('index', $modelClass, $resourceClass) as $parameter) {
            if (($parameter['name'] ?? null) === 'order') {
                /** @var list<string> */
                return $parameter['schema']['items']['enum'];
            }
        }

        return [];
    }

    /**
     * List the order key of each sortable column of each sortable relation.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return list<string>
     */
    private function relationSortKeys(CompiledSchema $compiled): array
    {
        $keys = [];

        foreach (array_keys($compiled->getSortableRelations()) as $relation) {
            foreach (SchemaCompiler::compile((string) $compiled->getTraversableResource($relation))->getSortableColumns() as $column) {
                $keys[] = $relation . '.' . $column;
            }
        }

        return $keys;
    }

    /**
     * Build the filter document of each resource in the fixture map, keyed by
     * its model.
     *
     * @return array<class-string<\Illuminate\Database\Eloquent\Model>, array{properties: array<string, array<string, mixed>>}>
     */
    private function filterDocuments(): array
    {
        $built     = $this->builtFilterDocuments();
        $documents = [];

        foreach ($this->resourceMap() as $modelClass => $resourceClass) {
            $documents[$modelClass] = $built[FilterSchemaBuilder::nameOf($resourceClass)];
        }

        return $documents;
    }

    /**
     * Build the filter document of every configured resource, keyed by name.
     *
     * @return array<string, array{properties: array<string, array<string, mixed>>}>
     */
    private function builtFilterDocuments(): array
    {
        $claimed = [];

        /** @var array<string, array{properties: array<string, array<string, mixed>>}> */
        return $this->makeApplication()->make(FilterSchemaBuilder::class)->build($claimed);
    }

    /**
     * List the tokens each column of a filter document takes, keyed by column.
     *
     * @param  array{properties: array<string, array<string, mixed>>}  $document
     * @return array<string, list<string>>
     */
    private function filteredColumns(array $document): array
    {
        $columns = [];

        foreach ($document['properties'] as $key => $schema) {

            $operators = is_array($schema['anyOf'] ?? null) ? $schema['anyOf'][1] ?? null : $schema;

            if (str_starts_with($key, '$') || !is_array($operators) || !isset($operators['minProperties']) || !is_array($operators['properties'] ?? null)) {
                continue;
            }

            $columns[$key] = array_map('strval', array_keys($operators['properties']));
        }

        return $columns;
    }

    /**
     * Determine whether the gate admits the token on the column.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface  $gate
     * @param  string  $column
     * @param  string  $token
     * @return bool
     */
    private function isAdmitted(QuerySurface $gate, string $column, string $token): bool
    {
        try {
            $gate->guardFilterOperator($column, $token);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * Read the exported document over the given resource bound to the user
     * model, as an index of it carries.
     *
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    private function documentOf(string $resourceClass): array
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => $resourceClass]);

        $this->router()->get('documented-users', [PathFixtureController::class, 'index']);

        return $this->export();
    }

    /**
     * Assert that the filter document the resource exports accepts the given
     * filters.
     *
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $filters
     * @return void
     */
    private function assertFilterDocumentAccepts(string $resourceClass, array $filters): void
    {
        $result = $this->validateAgainstExported($this->documentOf($resourceClass), '/components/schemas/' . FilterSchemaBuilder::nameOf($resourceClass), Helper::toJSON($filters));

        self::assertTrue($result->isValid(), $this->formatErrors($result));
    }

    /**
     * Dispatch a filtered request against the repository-backed route.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function filtered(array $filters): TestResponse
    {
        return $this->getJson('/queried-users?filters=' . urlencode((string) json_encode($filters)));
    }

    /**
     * Dispatch a filtered request against the aliased article route.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function filteredArticles(array $filters): TestResponse
    {
        return $this->getJson('/aliased-articles?filters=' . urlencode((string) json_encode($filters)));
    }

    /**
     * Seed articles whose slugs order differently from their insertion order.
     *
     * @return void
     */
    private function seedArticles(): void
    {
        foreach (['first-article', 'wide-article', 'second-article'] as $slug) {
            Article::create([
                'user_id' => 1,
                'title'   => ucfirst(str_replace('-', ' ', $slug)),
                'slug'    => $slug,
                'body'    => str_repeat('lorem ipsum dolor ', 10),
                'summary' => 'A concise summary of the ' . $slug . ' body content.',
                'status'  => 'published',
                'views'   => 10,
            ]);
        }
    }

    /**
     * Collect every distinct operator-shaped token in the document's keys and
     * values, other than the JSON Schema $ref keyword.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    private function operatorTokensIn(array $document): array
    {
        $tokens = [];

        $this->collectTokens($document, $tokens);

        $found = array_keys($tokens);

        sort($found);

        return $found;
    }

    /**
     * Collect the operator-shaped tokens carried by a node into the given set.
     *
     * @param  mixed  $node
     * @param  array<string, true>  $tokens
     * @return void
     */
    private function collectTokens(mixed $node, array &$tokens): void
    {
        if (is_array($node)) {

            foreach ($node as $key => $value) {

                if ($key !== '$ref') {
                    $this->collectTokens((string) $key, $tokens);
                }

                $this->collectTokens($value, $tokens);
            }

            return;
        }

        if (!is_string($node)) {
            return;
        }

        preg_match_all(self::TOKEN_PATTERN, $node, $matches);

        foreach ($matches[0] as $token) {
            $tokens[$token] = true;
        }
    }

    /**
     * List the filter surfaces the document advertises, one per property.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, array{model: class-string<\Illuminate\Database\Eloquent\Model>, property: string, key: string, capability: string, operators: array<int, string>}>
     */
    private function advertisedSurfaces(array $document): array
    {
        $surfaces = [];

        foreach ($this->resourceMap() as $modelClass => $resourceClass) {

            /** @var array<string, array<string, mixed>> $properties */
            $properties = $this->propertiesOf($document, $resourceClass);

            foreach ($properties as $propertyKey => $property) {

                if (!is_array($property[self::SURFACE_KEY]['filter'] ?? null)) {
                    continue;
                }

                /** @var array{key: string, capability: string, operators: array<int, string>} $filter */
                $filter = $property[self::SURFACE_KEY]['filter'];

                $surfaces[] = [
                    'model'      => $modelClass,
                    'property'   => $propertyKey,
                    'key'        => $filter['key'],
                    'capability' => $filter['capability'],
                    'operators'  => $filter['operators'],
                ];
            }
        }

        return $surfaces;
    }

    /**
     * Read the properties the document carries for a resource's schema.
     *
     * @param  array<string, mixed>  $document
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    private function propertiesOf(array $document, string $resourceClass): array
    {
        $schema = $document['components']['schemas'][SchemaComponentName::fromResource($resourceClass)] ?? null;

        self::assertIsArray($schema, sprintf('The document carries no schema for "%s".', $resourceClass));

        /** @var array<string, mixed> */
        return $schema['properties'] ?? [];
    }

    /**
     * Read the query surface advertised on one property of one resource.
     *
     * @param  array<string, mixed>  $document
     * @param  class-string  $resourceClass
     * @param  string  $property
     * @return array<string, array<string, mixed>>
     */
    private function advertisedSurfaceOn(array $document, string $resourceClass, string $property): array
    {
        $properties = $this->propertiesOf($document, $resourceClass);
        $surface    = $properties[$property][self::SURFACE_KEY] ?? null;

        self::assertIsArray($surface, sprintf('The document advertises no query surface on the "%s" property.', $property));

        /** @var array<string, array<string, mixed>> */
        return $surface;
    }

    /**
     * Read the key advertised for one part of a property's query surface.
     *
     * @param  array<string, mixed>  $document
     * @param  class-string  $resourceClass
     * @param  string  $property
     * @param  string  $part
     * @return string
     */
    private function advertisedKeyOn(array $document, string $resourceClass, string $property, string $part): string
    {
        $key = $this->advertisedSurfaceOn($document, $resourceClass, $property)[$part]['key'] ?? null;

        self::assertIsString($key, sprintf('The document advertises no "%s" key on the "%s" property.', $part, $property));

        return $key;
    }

    /**
     * Read the operators advertised for one column of one model.
     *
     * @param  array<string, mixed>  $document
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  string  $key
     * @return array<int, string>
     */
    private function advertisedOperatorsFor(array $document, string $modelClass, string $key): array
    {
        foreach ($this->advertisedSurfaces($document) as $surface) {

            if ($surface['model'] === $modelClass && $surface['key'] === $key) {
                return $surface['operators'];
            }
        }

        self::fail(sprintf('The document advertises no filter surface for the "%s" key.', $key));
    }

    /**
     * Build the request-time gate for a model as the criteria layer does.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function gateFor(string $modelClass): QuerySurface
    {
        return new QuerySurface(
            SchemaCompiler::compile($this->resourceMap()[$modelClass]),
            new RelationTraversal(app(SchemaIntrospectionProvider::class), $this->resourceMap()),
            new CallerAdmission(Request::create('/'), new GuardEvaluator),
            $this->registry(),
        );
    }

    /**
     * Register a prefix-match token admitted on ordered columns only.
     *
     * @return void
     */
    private function registerStartsWith(): void
    {
        $this->registry()->register(self::STARTS_WITH, static function (): void {}, Capability::RANGE);
    }

    /**
     * Read the live operator vocabulary, structural operators included.
     *
     * @return array<int, string>
     */
    private function vocabulary(): array
    {
        return [...$this->registry()->tokens(), ...FilterApplier::STRUCTURAL_OPERATORS];
    }

    /**
     * Resolve the bound operator registry.
     *
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry
     */
    private function registry(): OperatorRegistry
    {
        /** @var \SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry */
        return $this->makeApplication()->make(OperatorRegistry::class);
    }

    /**
     * Run the export use case against the container-resolved graph.
     *
     * @return array<string, mixed>
     */
    private function export(): array
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->makeApplication()->make(ExportOpenApiComponents::class);

        return $exporter->export()->document;
    }

    /**
     * Assemble the manual from the shipped sections plus generated ones.
     *
     * @return void
     */
    private function assembleManual(): void
    {
        $this->docsDir = sys_get_temp_dir() . '/api-toolkit-docs-' . uniqid('', true);

        mkdir($this->docsDir);

        foreach (glob(dirname(__DIR__, 3) . '/resources/api-docs/*.md') ?: [] as $file) {
            copy($file, $this->docsDir . '/' . basename($file));
        }

        Config::set('api-toolkit.openapi.docs_path', $this->docsDir);

        $command = $this->artisan('api-toolkit:docs:generate');

        assert($command instanceof PendingCommand);

        $command->assertSuccessful();
    }

    /**
     * Register the documented routes reaching every mapped resource.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        $router = $this->router();

        $router->get('users', [PathFixtureController::class, 'index']);
        $router->get('logs', [PathLogController::class, 'index']);
        $router->get('articles', [PathArticleController::class, 'index']);
    }

    /**
     * Register the repository-backed route the wire-level refusal goes through.
     *
     * @return void
     */
    private function registerSearchRoute(): void
    {
        Route::middleware(ParseApiQuery::class)->get('/searched-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(SearchableFilterableUserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, SearchableFilterableUserResource::class);
        });
    }

    /**
     * Register the route the filtered requests are dispatched against.
     *
     * @return void
     */
    private function registerQueryRoute(): void
    {
        Route::middleware(ParseApiQuery::class)->get('/queried-users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });
    }

    /**
     * Register the repository-backed route the aliased surface goes through.
     *
     * @return void
     */
    private function registerAliasedArticleRoute(): void
    {
        Route::middleware(ParseApiQuery::class)->get('/aliased-articles', function (ArticleRepository $repository): ApiResourceCollection {

            $articles = $repository->usingResource(AliasedSurfaceArticleResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($articles, AliasedSurfaceArticleResource::class);
        });
    }

    /**
     * The fixture resource map the document and the gates are both read from.
     *
     * @return array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>
     */
    private function resourceMap(): array
    {
        return [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
            Log::class          => CapabilitySpectrumLogResource::class,
            Article::class      => AliasedSurfaceArticleResource::class,
        ];
    }

    /**
     * Resolve the application router singleton.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        return $this->makeApplication()->make(Router::class);
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function makeApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
