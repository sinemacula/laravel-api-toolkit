<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\ResourceSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;
use SineMacula\ApiToolkit\Repositories\Criteria\OperatorRegistry;
use SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Log;
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
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Contract tests binding the emitted query surface to the live package.
 *
 * The exported document makes two promises a consumer cannot check for itself:
 * that every operator it names is one the package still ships, and that the
 * operators it advertises for a column are the operators a request may pair
 * with that column.
 *
 * The operator scan reads the whole document, including the Markdown manual
 * assembled into the description, so a token the registry no longer holds is
 * reported wherever it survives: in the shared parameter grammar, in a
 * property's own surface, or in the shipped prose describing either. The
 * document side of it is narrowed against the live registry, so removing a
 * token leaves only the hand-written prose to report, which is what the scan
 * exists to catch.
 *
 * The agreement between the document and enforcement is asserted at the gate,
 * which decides every filter predicate the query layer emits, so the whole
 * matrix can be walked without an operator whose SQL a development connection
 * cannot answer standing between the assertion and the answer. The two
 * directions read the capability matrix on both sides, so what they carry is
 * narrower than a full independent check: that the advertised key is the key
 * the gate accepts under an alias, that the matrix governs no operator outside
 * the union of its permitted sets, and that a refusal names the advertised set
 * back to the client. The matrix itself is pinned by its own unit tests. One
 * resource in the map presents the columns it is filtered and ordered by under
 * an alias, so the walk reaches a key that is not the property it hangs on
 * rather than only the cases where the two names coincide.
 *
 * Two wire-level cases close the loop: the operators a refusal names a client
 * may send are the operators the document told it to send, and the key
 * advertised for an aliased field is the key a filter and an order send it
 * under, the property carrying its value back being refused as undeclared.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceSchemaBuilder::class)]
#[CoversClass(QueryParameterBuilder::class)]
#[CoversClass(QuerySurface::class)]
#[CoversClass(Capability::class)]
final class OpenApiQuerySurfaceContractTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var string The pattern matching an operator-shaped token in any string the document carries. */
    private const string TOKEN_PATTERN = '/\$[A-Za-z][A-Za-z0-9_]*/';

    /** @var string The property extension naming what a field may be queried by. */
    private const string SURFACE_KEY = 'x-query-surface';

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
     * Test that every operator token the document carries anywhere is one the
     * live operator vocabulary still holds, so a token the package has dropped
     * cannot survive in the shipped grammar, in a property's own surface, or in
     * the prose describing either.
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
     * Test that the scan reports a token the registry no longer holds, so the
     * assertion above is the thing that catches a vocabulary the package has
     * changed rather than passing whatever it is given.
     *
     * The generated halves of the document are narrowed against the registry,
     * so what survives a removal is the hand-written prose naming the operator
     * table by hand. That prose is the drift the scan exists to refuse.
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
     * Test that a token the registry no longer holds is gone from every column
     * the document advertises and from the shared filter grammar, an unbound
     * token being refused as an undeclared key rather than dispatched.
     *
     * @return void
     */
    public function testATokenTheRegistryNoLongerHoldsIsGoneFromEveryPerColumnSurface(): void
    {
        $this->registerRoutes();

        $this->registry()->remove('$in');

        $document = $this->export();
        $surfaces = $this->advertisedSurfaces($document);

        self::assertNotEmpty($surfaces);

        foreach ($surfaces as $surface) {
            self::assertNotContains(
                '$in',
                $surface['operators'],
                sprintf('The document advertises "$in" on the "%s" key, which the registry no longer binds.', $surface['key']),
            );
        }

        /** @var array<int, string> $vocabulary */
        $vocabulary = $document['components']['parameters']['Filters']['schema']['x-operators'];

        self::assertNotContains('$in', $vocabulary);
    }

    /**
     * Test that a column whose only operator has left the registry is no longer
     * advertised as filterable at all, rather than as a capability answering
     * nothing the filter engine can dispatch.
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
     * Test that every operator the document advertises for a column is one the
     * request-time gate permits on that column.
     *
     * @return void
     */
    public function testEveryAdvertisedOperatorIsPermittedByTheGate(): void
    {
        $this->registerRoutes();

        $asserted = 0;

        foreach ($this->advertisedSurfaces($this->export()) as $surface) {

            $gate  = $this->gateFor($surface['model']);
            $model = $this->modelFor($surface['model']);

            foreach ($surface['operators'] as $operator) {

                try {
                    $gate->guardFilterOperator($surface['key'], $operator, $model);
                } catch (ValidationException $exception) {
                    self::fail(sprintf(
                        'The document advertises "%s" on the "%s" key, which the gate refuses: %s',
                        $operator,
                        $surface['key'],
                        (string) json_encode($exception->errors()),
                    ));
                }

                $asserted++;
            }
        }

        self::assertGreaterThan(0, $asserted);
    }

    /**
     * Test that every operator the document omits from a column is one the
     * request-time gate refuses on that column, naming the operators the
     * document does advertise.
     *
     * Only the operators the capability matrix governs are asserted: a token
     * outside it was bound to a handler by the application, which the package
     * deliberately leaves to the application rather than refusing.
     *
     * @return void
     */
    public function testEveryOmittedOperatorIsRefusedByTheGate(): void
    {
        $this->registerRoutes();

        $asserted = 0;

        foreach ($this->advertisedSurfaces($this->export()) as $surface) {

            $gate     = $this->gateFor($surface['model']);
            $model    = $this->modelFor($surface['model']);
            $accepted = implode(', ', $surface['operators']);

            foreach (array_diff($this->governedOperators(), $surface['operators']) as $operator) {

                $message = sprintf(
                    'The "%s" operator is not permitted on the "%s" key for this resource, which accepts %s.',
                    $operator,
                    $surface['key'],
                    $accepted,
                );

                try {
                    $gate->guardFilterOperator($surface['key'], $operator, $model);

                    self::fail(sprintf('The document omits "%s" from the "%s" key, which the gate permits.', $operator, $surface['key']));
                } catch (ValidationException $exception) {
                    self::assertSame(['filters.' . $surface['key'] . '.' . $operator => [$message]], $exception->errors());
                }

                $asserted++;
            }
        }

        self::assertGreaterThan(0, $asserted);
    }

    /**
     * Test that the walk above reaches a column declared with every capability,
     * so the agreement is asserted over the whole matrix rather than over the
     * two or three cases a fixture happens to declare.
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
     * Test that a refusal on the wire names exactly the operators the document
     * advertises for the refused column, so the correction a client is handed
     * is the surface it was documented against.
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
     * Test that the walk over the documented surfaces reaches a key that is not
     * the property it hangs on, so the agreement it asserts is asserted under
     * an alias rather than only where the two names happen to coincide.
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
     * Test that the key the document advertises for an aliased field is the
     * column the field is queried by rather than the property the response
     * carries its value under, for an order as well as for a filter.
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
     * Test that the key the document advertises for an aliased field is the key
     * a request sends it under: sending the advertised key narrows the
     * collection and the rows come back under the aliased property, while
     * sending the property name is refused as an undeclared key.
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
     * Test that the key the document advertises for an aliased field is the key
     * an order names it by, the rows coming back under the aliased property in
     * the order the column holds, while the property name is refused.
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
     * Test that the shipped manual tables the parameters the document defines
     * and names every one of them, so a parameter gained or lost by the builder
     * cannot leave the hand-written table behind.
     *
     * @return void
     */
    public function testTheManualTablesEveryParameterTheDocumentDefines(): void
    {
        $this->registerRoutes();

        $manual = $this->manual();
        $names  = $this->parameterNames($this->export());
        $tabled = $this->tabledParameterNames();

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
     * Test that the manual splits the grammar the way the operations do: the
     * parameters it tables first are the ones a write carries, and the ones it
     * tables next are the collection grammar an index carries beyond them.
     *
     * @return void
     */
    public function testTheManualSplitsTheGrammarTheWayTheOperationsDo(): void
    {
        $this->registerRoutes();

        $names  = $this->parameterNames($this->export());
        $tabled = $this->tabledParameterNames();

        $shaping = $this->parametersFor('store', $names);

        self::assertSame($shaping, array_slice($tabled, 0, count($shaping)));

        // The manual tables the selection grammar next, holding back the two
        // parameters it describes under cursor paging instead.
        $selection = array_values(array_diff($this->parametersFor('index', $names), $shaping, ['cursor', 'pagination']));

        self::assertSame($selection, array_slice($tabled, count($shaping), count($selection)));
    }

    /**
     * Test that the operator table the manual carries by hand is the live
     * vocabulary, so a token the package adds or drops cannot leave the shipped
     * prose describing a grammar the API does not answer.
     *
     * @return void
     */
    public function testTheManualOperatorTableIsTheLiveVocabulary(): void
    {
        $tabled = array_map($this->unquote(...), $this->tabledRows('| Operator', 0));

        sort($tabled);

        /** @var \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue $catalogue */
        $catalogue = $this->makeApplication()->make(MetadataCatalogue::class);

        $registered = $catalogue->getOperatorTokens();

        sort($registered);

        self::assertSame($registered, $tabled);
    }

    /**
     * Test that the capability matrix the manual tables by hand is the matrix
     * the enum holds, row for row and in the same order, so an operator moved
     * between capabilities cannot leave the shipped prose behind.
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
            static fn (Capability $case): array => $case->permittedOperators(),
            Capability::cases(),
        );

        self::assertSame($expected, $tabled);
    }

    /**
     * Test that the worked surface example the manual carries names a
     * capability and lists exactly the operators that capability answers.
     *
     * @return void
     */
    public function testTheManualSurfaceExampleListsTheOperatorsItsCapabilityAnswers(): void
    {
        $manual = $this->manual();

        self::assertSame(1, preg_match('/"capability": "(?<capability>[a-z]+)"/', $manual, $capability));
        self::assertSame(1, preg_match('/"operators": \[(?<operators>[^\]]+)\]/', $manual, $operators));

        $case = Capability::from($capability['capability']);

        self::assertSame($case->permittedOperators(), $this->tokensIn($operators['operators']));
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
     * Read one column of every row of the manual table whose header line starts
     * with the given prefix.
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
     * Map each parameter component name the document defines to the query
     * parameter name it carries.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, string>
     */
    private function parameterNames(array $document): array
    {
        /** @var array<string, array{name: string}> $parameters */
        $parameters = $document['components']['parameters'];

        return array_map(static fn (array $parameter): string => $parameter['name'], $parameters);
    }

    /**
     * List the query parameter names an action carries, resolved through the
     * component names the builder references.
     *
     * @param  string  $action
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private function parametersFor(string $action, array $names): array
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder $builder */
        $builder = $this->makeApplication()->make(QueryParameterBuilder::class);

        return array_values(array_map(
            static fn (array $reference): string => $names[str_replace('#/components/parameters/', '', $reference['$ref'])],
            $builder->referencesFor($action),
        ));
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
     * Seed the articles the aliased surface is queried over, whose slugs order
     * differently from the order they are written in.
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
     * Collect every distinct operator-shaped token the document carries in a
     * value, in sorted order.
     *
     * Keys are left out: the only dollar-prefixed key the document carries is
     * the JSON Schema reference keyword, which names a component rather than an
     * operator.
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

            foreach ($node as $value) {
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
     * List the filter surfaces the document advertises, one per property
     * carrying one, each naming the model whose column it stands for and the
     * property it hangs on.
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
     * Read the query surface the document advertises on a single property of a
     * single resource.
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
     * Read the key the document advertises for one part of the query surface on
     * a single property.
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
     * Read the operators the document advertises for a single column of a
     * single model.
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
     * Build the request-time gate for a model exactly as the criteria layer
     * builds it, from the compiled schema of the resource mapped to it.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\QuerySurface
     */
    private function gateFor(string $modelClass): QuerySurface
    {
        $compiled = SchemaCompiler::compile($this->resourceMap()[$modelClass]);

        return new QuerySurface(
            $compiled->getFilterableColumns(),
            $compiled->getSortableColumns(),
            $compiled->getTraversableRelations(),
            $this->modelFor($modelClass),
            $this->resourceMap(),
        );
    }

    /**
     * Instantiate the model a gate is bound to.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function modelFor(string $modelClass): Model
    {
        return new $modelClass;
    }

    /**
     * List every operator token the capability matrix governs.
     *
     * @return array<int, string>
     */
    private function governedOperators(): array
    {
        $tokens = [];

        foreach (Capability::cases() as $case) {

            foreach ($case->permittedOperators() as $token) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * Read the live operator vocabulary: the registered tokens plus the
     * structural operators applied outside the registry.
     *
     * @return array<int, string>
     */
    private function vocabulary(): array
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue $catalogue */
        $catalogue = $this->makeApplication()->make(MetadataCatalogue::class);

        return [...$catalogue->getOperatorTokens(), ...$catalogue->getStructuralOperators()];
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
     * Point the manual at a copy of the shipped Markdown sections and generate
     * the reference sections beside them, so the assembled description carries
     * both the committed prose and the generated tables.
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
     * Register the repository-backed route the wire-level refusal is driven
     * through, under a path of its own so it is not the documented one.
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
     * Register the repository-backed route the aliased surface is driven
     * through, so a request names the keys the document advertises for a
     * resource whose queried columns are carried under other names.
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
