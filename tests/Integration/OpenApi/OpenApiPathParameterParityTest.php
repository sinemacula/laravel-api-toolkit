<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Builder\PathParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Resolution\RouteKeySchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\RoutePatternTranslator;
use Tests\Fixtures\Models\Ticket;
use Tests\Fixtures\OpenApi\PathBindingController;
use Tests\TestCase;

/**
 * Parity test between each exported path parameter schema and the router.
 *
 * A value the schema accepts must dispatch, and a value it rejects must not.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PathParameterBuilder::class)]
#[CoversClass(RoutePatternTranslator::class)]
#[CoversClass(RouteKeySchemaResolver::class)]
final class OpenApiPathParameterParityTest extends TestCase
{
    /** @var string The identifier under which the exported document is registered. */
    private const string DOCUMENT_ID = 'https://api.example.com/openapi.json';

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        assert($this->app !== null);

        $this->app->make(Router::class)->group(['middleware' => SubstituteBindings::class], static function (Router $router): void {
            $router->get('uuids/{value}', [PathBindingController::class, 'value'])->whereUuid('value');
            $router->get('numbers/{value}', [PathBindingController::class, 'value'])->whereNumber('value');
            $router->get('states/{value}', [PathBindingController::class, 'value'])->whereIn('value', ['draft', 'published']);
            $router->get('codes/{value}', [PathBindingController::class, 'value'])->whereAlpha('value');
            $router->get('statuses/{status}', [PathBindingController::class, 'status']);
            $router->get('tickets/{ticket}', [PathBindingController::class, 'ticket']);
        });
    }

    /**
     * Provide each constrained or enum-bound path with a value the schema
     * accepts and one it rejects.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function derivationProvider(): iterable
    {
        yield 'whereUuid' => ['/uuids/{value}', '0b5c7a3e-9f1d-4c2b-8e6a-1d2f3a4b5c6d', '0b5c7a3e9f1d4c2b8e6a1d2f3a4b5c6d'];
        yield 'whereNumber' => ['/numbers/{value}', '42', '-1'];
        yield 'whereIn' => ['/states/{value}', 'published', 'archived'];
        yield 'whereAlpha' => ['/codes/{value}', 'abc', 'abc1'];
        yield 'string-backed enum' => ['/statuses/{status}', 'active', 'unknown'];
    }

    /**
     * Test that the schema and the router agree on each derivation.
     *
     * @param  string  $path
     * @param  string  $accepted
     * @param  string  $rejected
     * @return void
     */
    #[DataProvider('derivationProvider')]
    public function testSchemaAndRouterAgree(string $path, string $accepted, string $rejected): void
    {
        self::assertTrue($this->isAcceptedBySchema($path, $accepted));
        $this->get($this->uri($path, $accepted))->assertOk()->assertContent($accepted);

        self::assertFalse($this->isAcceptedBySchema($path, $rejected));
        $this->get($this->uri($path, $rejected))->assertNotFound();
    }

    /**
     * Test that a UUID-keyed model and the router agree on syntax.
     *
     * @return void
     */
    public function testUuidKeyedModelAndRouterAgree(): void
    {
        $ticket = Ticket::create(['code' => 'A1']);

        self::assertTrue($this->isAcceptedBySchema('/tickets/{ticket}', $ticket->id));
        $this->get('/tickets/' . $ticket->id)->assertOk()->assertContent($ticket->id);

        self::assertFalse($this->isAcceptedBySchema('/tickets/{ticket}', 'A1'));
        $this->get('/tickets/A1')->assertNotFound();
    }

    /**
     * Test that a valid but missing UUID is not found, as a matter of existence
     * rather than syntax.
     *
     * @return void
     */
    public function testValidButMissingUuidIsNotFound(): void
    {
        $missing = (string) Str::uuid();

        self::assertTrue($this->isAcceptedBySchema('/tickets/{ticket}', $missing));
        $this->get('/tickets/' . $missing)->assertNotFound();
    }

    /**
     * Define the database migrations, adding the tickets table.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::dropIfExists('tickets');
        Schema::create('tickets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code');
            $table->string('priority')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Determine whether the path's exported parameter schema accepts a value.
     *
     * An integer schema reads a digit string as the number a client sends.
     *
     * @param  string  $path
     * @param  string  $value
     * @return bool
     */
    private function isAcceptedBySchema(string $path, string $value): bool
    {
        assert($this->app !== null);

        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->app->make(ExportOpenApiComponents::class);
        $document = $exporter->export()->document;

        /** @var array{schema: array<string, mixed>} $parameter */
        $parameter = $document['paths'][$path]['get']['parameters'][0];
        $integer   = ($parameter['schema']['type'] ?? null) === 'integer' && preg_match('/^-?[0-9]+$/', $value) === 1;

        $validator = new Validator;
        $resolver  = $validator->resolver();

        assert($resolver instanceof SchemaResolver);

        $exported = Helper::toJSON($document);

        self::assertIsObject($exported);

        $resolver->registerRaw($exported, self::DOCUMENT_ID);

        $pointer = '#/paths/' . rawurlencode(str_replace('/', '~1', $path)) . '/get/parameters/0/schema';

        return $validator->validate($integer ? (int) $value : $value, self::DOCUMENT_ID . $pointer)->isValid();
    }

    /**
     * Fill the path template's one parameter with a value.
     *
     * @param  string  $path
     * @param  string  $value
     * @return string
     */
    private function uri(string $path, string $value): string
    {
        return (string) preg_replace('/\{[^}]+\}/', $value, $path);
    }
}
