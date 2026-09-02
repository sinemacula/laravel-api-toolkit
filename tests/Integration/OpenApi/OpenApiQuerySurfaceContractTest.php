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
use Tests\Fixtures\Models\Log;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathLogController;
use Tests\Fixtures\Repositories\UserRepository;
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
 * operators it advertises for a column are exactly the operators a request may
 * pair with that column. Both are asserted against the live objects rather than
 * against a restatement of them, so a vocabulary the package has changed and a
 * surface the gate disagrees with fail the exporter suite instead of shipping.
 *
 * The operator scan reads the whole document, including the Markdown manual
 * assembled into the description, so a token deleted from the registry is
 * reported wherever it survives: in the shared parameter grammar, in a
 * property's own surface, or in the shipped prose describing either.
 *
 * The agreement between the document and enforcement is asserted at the gate,
 * which decides every filter predicate the query layer emits, so the whole
 * matrix can be walked without an operator whose SQL a development connection
 * cannot answer standing between the assertion and the answer. One wire-level
 * case closes the loop: the operators a refusal names a client may send are the
 * operators the document told it to send.
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
            array_map('unlink', glob($this->docsDir . '/*') ?: []);
            @rmdir($this->docsDir);
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
     * A shipped token is bound into the capability matrix as well as into the
     * registry, so dropping it from the registry alone leaves every column
     * declared for it still advertising it. That is the drift the scan exists
     * to refuse.
     *
     * @return void
     */
    public function testATokenTheRegistryNoLongerHoldsIsReportedByTheScan(): void
    {
        $this->registerRoutes();

        $this->registry()->remove('$in');

        self::assertNotContains('$in', $this->vocabulary());
        self::assertContains('$in', $this->operatorTokensIn($this->export()));
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
     * carrying one, each naming the model whose column it stands for.
     *
     * @param  array<string, mixed>  $document
     * @return array<int, array{model: class-string<\Illuminate\Database\Eloquent\Model>, key: string, capability: string, operators: array<int, string>}>
     */
    private function advertisedSurfaces(array $document): array
    {
        $surfaces = [];

        foreach ($this->resourceMap() as $modelClass => $resourceClass) {

            $schema = $document['components']['schemas'][SchemaComponentName::fromResource($resourceClass)] ?? null;

            self::assertIsArray($schema, sprintf('The document carries no schema for "%s".', $resourceClass));

            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'] ?? [];

            foreach ($properties as $property) {

                if (!is_array($property[self::SURFACE_KEY]['filter'] ?? null)) {
                    continue;
                }

                /** @var array{key: string, capability: string, operators: array<int, string>} $filter */
                $filter = $property[self::SURFACE_KEY]['filter'];

                $surfaces[] = [
                    'model'      => $modelClass,
                    'key'        => $filter['key'],
                    'capability' => $filter['capability'],
                    'operators'  => $filter['operators'],
                ];
            }
        }

        return $surfaces;
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
