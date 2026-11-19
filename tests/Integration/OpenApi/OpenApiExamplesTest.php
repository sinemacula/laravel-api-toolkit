<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Examples\Body;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleComposer;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleGenerator;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleValidator;
use SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleGenerationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleMismatchException;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\NumericKeyRequestInput;
use Tests\Fixtures\OpenApi\PathExampleController;
use Tests\Fixtures\OpenApi\PathFixtureController;
use Tests\Fixtures\OpenApi\PathKeyedArrayController;
use Tests\Fixtures\OpenApi\PathNumericKeyController;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PresenceRequestInput;
use Tests\Fixtures\OpenApi\SignUpRequestInput;
use Tests\Fixtures\Resources\ExampledUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end tests for the examples of an exported document.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExampleComposer::class)]
#[CoversClass(ExampleGenerator::class)]
#[CoversClass(ExampleValidator::class)]
#[CoversClass(SchemaDocument::class)]
#[CoversClass(Body::class)]
final class OpenApiExamplesTest extends TestCase
{
    use ValidatesOpenApiDocuments;

    /** The rules source behind each request body whose rules its schema fully expresses */
    private const array REQUEST_SOURCES = [
        '/presence' => PresenceRequestInput::class,
        '/numbered' => NumericKeyRequestInput::class,
        '/sign-up'  => SignUpRequestInput::class,
    ];

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

        Config::set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
        ]);
    }

    /**
     * Test that every example matches its schema, every JSON request body and
     * success body carries one unless multipart or wholly undocumented, and the
     * document stays valid OpenAPI 3.1.
     *
     * @return void
     */
    public function testEveryRequestAndSuccessBodyCarriesMatchingExamples(): void
    {
        $this->registerRoutes();
        $this->router()->post('upload', [PathRequestBodyController::class, 'upload']);
        $this->router()->get('closure', static fn (): null => null);

        $document = $this->export()->document;
        $decoded  = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $carrying = [];
        $lacking  = [];

        self::assertIsArray($decoded);

        foreach (Body::in($decoded) as $body) {

            $media = $this->resolveReference($document, '#' . substr($body->schemaPointer(), 0, -strlen('/schema')));

            self::assertIsArray($media);

            if (!isset($media['examples'])) {
                $lacking[] = $body->target;

                continue;
            }

            $carrying[] = $body->target;

            self::assertIsArray($media['examples']);

            foreach ($media['examples'] as $name => $example) {
                $this->assertExampleMatches($document, $body, (string) $name, $example);
            }
        }

        self::assertContains('GET /users response 200', $carrying);
        self::assertContains('POST /presence request body', $carrying);
        self::assertContains('GET /union response 200', $carrying);
        self::assertContains('GET /users/{user} response 404', $carrying);
        self::assertSame([
            'POST /presence response 200',
            'POST /keyed response 201',
            'POST /numbered response 201',
            'POST /sign-up response 200',
            'POST /upload request body',
            'POST /upload response 200',
            'GET /closure response 200',
        ], $lacking);
        $this->assertValidDocument($document);
    }

    /**
     * Test that each generated request example passes the rules it was
     * generated from, for rules the schema fully expresses.
     *
     * @return void
     */
    public function testGeneratedRequestExamplesPassTheirRules(): void
    {
        $this->registerRoutes();

        $document = $this->export()->document;

        foreach (self::REQUEST_SOURCES as $path => $source) {

            $example = $this->examplesOf($document, '/paths/' . str_replace('/', '~1', $path) . '/post/requestBody/content/application~1json')['default'];

            self::assertIsArray($example);

            $checked = Validator::make($example, $source::rules());

            self::assertTrue($checked->passes(), sprintf('The example of %s fails its rules: %s', $path, json_encode($checked->errors()->all(), JSON_THROW_ON_ERROR)));
        }
    }

    /**
     * Test that a rules source's examples are used, a confirmation repeats the
     * field it confirms, a container value is the whole list, and a date
     * renders in the rule's own format.
     *
     * @return void
     */
    public function testRequestExampleUsesTheAuthorValues(): void
    {
        $this->registerRoutes();

        self::assertSame(['default' => [
            'email'                 => 'user@example.com',
            'password'              => 'correct-horse',
            'password_confirmation' => 'correct-horse',
            'code'                  => 'ABC',
            'born_on'               => '01/01/2025',
            'interests'             => [['name' => 'chess'], ['name' => 'go']],
        ]], $this->examplesOf($this->export()->document, '/paths/~1sign-up/post/requestBody/content/application~1json'));
    }

    /**
     * Test that an index carries a length-aware and a cursor example.
     *
     * @return void
     */
    public function testIndexCarriesALengthAwareAndACursorExample(): void
    {
        $this->registerRoutes();

        $page = 'https://example.com/resources?page=1';
        $data = [['_type' => 'users', 'id' => 1, 'name' => 'string', 'email' => 'string']];

        self::assertSame([
            'Length-aware page' => [
                'data'  => $data,
                'meta'  => ['total' => 1, 'total_capped' => false, 'count' => 1, 'continue' => false],
                'links' => ['self' => $page, 'first' => $page, 'prev' => null, 'next' => null, 'last' => $page],
            ],
            'Cursor page' => [
                'data'  => $data,
                'meta'  => ['continue' => false],
                'links' => ['self' => 'https://example.com/resources', 'prev' => null, 'next' => null],
            ],
        ], $this->examplesOf($this->export()->document, '/paths/~1users/get/responses/200/content/application~1json'));
    }

    /**
     * Test that a two-shape response carries one example per shape.
     *
     * @return void
     */
    public function testTwoShapeResponseCarriesOneExamplePerShape(): void
    {
        $this->registerRoutes();

        self::assertSame([
            'SignedInShape'      => ['data' => ['status' => 'signed_in', 'token' => 'string']],
            'PendingSignInShape' => ['data' => ['status' => 'pending', 'choices' => ['string']]],
        ], $this->examplesOf($this->export()->document, '/paths/~1union/get/responses/200/content/application~1json'));
    }

    /**
     * Test that a resource field's declared example is exported as examples,
     * keeps the type inferred for it, and is used by the response example.
     *
     * @return void
     */
    public function testDeclaredResourceExampleKeepsItsTypeAndIsUsed(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => ExampledUserResource::class]);

        $this->router()->get('users/{user}', [PathFixtureController::class, 'show']);

        $document = $this->export()->document;

        self::assertSame(
            ['type' => 'string', 'examples' => ['Ada Lovelace']],
            $this->resolveReference($document, '#/components/schemas/ExampledUser/properties/name'),
        );
        self::assertSame(
            ['default' => ['data' => ['_type' => 'exampled_users', 'id' => 1, 'name' => 'Ada Lovelace']]],
            $this->examplesOf($document, '/paths/~1users~1{user}/get/responses/200/content/application~1json'),
        );
    }

    /**
     * Test that a required field the exporter finds no value for fails the
     * export with its pointer.
     *
     * @return void
     */
    public function testRequiredFieldWithoutAValueFailsTheExport(): void
    {
        $this->router()->post('codes', [PathExampleController::class, 'redeem']);

        $this->expectException(ExampleGenerationException::class);
        $this->expectExceptionMessage(
            'The exporter could not generate an example for POST /codes request body in the [public] audience: none of its values satisfies the schema at'
            . ' #/paths/~1codes/post/requestBody/content/application~1json/schema/properties/code. Give the field a value with fieldExamples() on its rules source.',
        );

        $this->export();
    }

    /**
     * Test that an author example its schema refuses fails the export, naming
     * where it sits.
     *
     * @return void
     */
    public function testInvalidAuthorExampleFailsTheExport(): void
    {
        $this->router()->post('codes', [PathExampleController::class, 'misredeem']);

        $this->expectException(ExampleMismatchException::class);
        $this->expectExceptionMessage(
            'The schema example [0] for #/paths/~1codes/post/requestBody/content/application~1json/schema/properties/code in the [public] audience'
            . ' does not match its schema at the root: The string should match pattern: ^[A-Z]{3}$',
        );

        $this->export();
    }

    /**
     * Register the routes whose bodies take generated examples.
     *
     * @return void
     */
    private function registerRoutes(): void
    {
        $router = $this->router();

        $router->get('users', [PathFixtureController::class, 'index']);
        $router->post('users', [PathFixtureController::class, 'store']);
        $router->get('users/{user}', [PathFixtureController::class, 'show']);
        $router->post('presence', [PathRequestBodyController::class, 'presence']);
        $router->post('keyed', [PathKeyedArrayController::class, 'store']);
        $router->post('numbered', [PathNumericKeyController::class, 'store']);
        $router->post('sign-up', [PathExampleController::class, 'signUp']);
        $router->get('union', [PathResponseSchemaController::class, 'union']);
        $router->get('widgets', [PathResponseSchemaController::class, 'widgets']);
    }

    /**
     * Run the export use case against the container-resolved graph.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(): ExportResult
    {
        assert($this->app !== null);

        return $this->app->make(ExportOpenApiComponents::class)->export();
    }

    /**
     * Assert that an example of a body matches the body's schema.
     *
     * @param  array<string, mixed>  $document
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\Body  $body
     * @param  string  $name
     * @param  mixed  $example
     * @return void
     */
    private function assertExampleMatches(array $document, Body $body, string $name, mixed $example): void
    {
        self::assertIsArray($example);

        $reference = $example['$ref'] ?? null;
        $example   = is_string($reference) ? $this->resolveReference($document, $reference) : $example;

        self::assertIsArray($example);

        $pointer = implode('/', array_map(rawurlencode(...), explode('/', $body->schemaPointer())));
        $result  = $this->validateAgainstExported($document, $pointer, json_decode(json_encode($example['value'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));

        self::assertTrue($result->isValid(), sprintf('The example [%s] of %s does not match its schema: %s', $name, $body->target, $this->formatErrors($result)));
    }

    /**
     * Read the example values of a media type object, decoded as written.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $media
     * @return array<array-key, mixed>
     */
    private function examplesOf(array $document, string $media): array
    {
        $examples = $this->resolveReference($document, '#' . $media . '/examples');

        self::assertIsArray($examples);

        $values  = array_map(static fn (mixed $example): mixed => is_array($example) ? $example['value'] ?? null : null, $examples);
        $decoded = json_decode(json_encode($values, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Resolve the application router.
     *
     * @return \Illuminate\Routing\Router
     */
    private function router(): Router
    {
        assert($this->app !== null);

        return $this->app->make(Router::class);
    }
}
