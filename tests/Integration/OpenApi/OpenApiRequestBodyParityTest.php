<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleGenerator;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Concerns\ValidatesOpenApiDocuments;
use Tests\Fixtures\Actors\ActorUser;
use Tests\Fixtures\Controllers\ArticleController;
use Tests\Fixtures\Models\Article;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\ParityProfileController;
use Tests\Fixtures\OpenApi\ParityRequestBodyController;
use Tests\Fixtures\Policies\ArticlePolicy;
use Tests\Fixtures\Resources\TaggedArticleResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Round-trip test sending each documented request body through its route.
 *
 * A body the exported schema accepts must be accepted by the route, and one it
 * refuses must be refused with the error the operation documents.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequestBodyResolver::class)]
#[CoversClass(RulesToSchemaTranslator::class)]
#[CoversClass(ExampleGenerator::class)]
final class OpenApiRequestBodyParityTest extends TestCase
{
    use RegistersApiExceptionHandler, ValidatesOpenApiDocuments;

    /** @var array<string, mixed> The exported default audience, as a client reads it */
    private array $document;

    /** @var \Tests\Fixtures\Actors\ActorUser The caller, who authors the routed article */
    private ActorUser $author;

    /** @var array<string, string> The concrete URI of each documented path */
    private array $uris = [];

    /**
     * Route every request body source and seed the rows its rules read.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Config::set('api-toolkit.resources.resource_map', [
            Article::class => TaggedArticleResource::class,
            User::class    => UserResource::class,
            Tag::class     => TagResource::class,
        ]);

        Gate::policy(Article::class, ArticlePolicy::class);

        Route::post('plain', [ParityRequestBodyController::class, 'plain']);
        Route::post('payload', [ParityRequestBodyController::class, 'payload']);
        Route::post('form-request', [ParityRequestBodyController::class, 'formRequest']);
        Route::post('attribute', [ParityRequestBodyController::class, 'attribute']);
        Route::put('users/{user}', [ParityProfileController::class, 'update']);
        Route::post('users/{user}/avatar', [ParityProfileController::class, 'avatar']);
        Route::apiResource('articles', ArticleController::class)->middleware([ParseApiQuery::class, 'auth', SubstituteBindings::class]);

        $this->document = $this->export();
        $this->author   = ActorUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        // The routed user already holds the documented email, so only the rule's ignore() accepts it
        $profile = $this->examplesOf('PUT', '/users/{user}');
        $user    = User::create(['name' => 'Bob', 'email' => reset($profile)['email'] ?? null]);
        $article = Article::create(['user_id' => $this->author->id, 'title' => 'Title', 'slug' => 'existing', 'body' => 'Body', 'summary' => 'Summary']);

        Tag::create(['name' => 'news']);

        $this->uris = [
            '/users/{user}'        => '/users/' . $user->id,
            '/users/{user}/avatar' => '/users/' . $user->id . '/avatar',
            '/articles/{article}'  => '/articles/' . $article->id,
        ];
    }

    /**
     * Provide each JSON request body by its method and path.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function documentedBodyProvider(): iterable
    {
        foreach (self::refusedBodyProvider() as $name => [$method, $path]) {
            yield $name => [$method, $path];
        }
    }

    /**
     * Test that every documented example of a JSON request body is accepted by
     * its route.
     *
     * @param  string  $method
     * @param  string  $path
     * @return void
     */
    #[DataProvider('documentedBodyProvider')]
    public function testDocumentedExamplesAreAcceptedByTheRoute(string $method, string $path): void
    {
        foreach ($this->examplesOf($method, $path) as $name => $body) {
            $this->assertAcceptedAsDocumented($method, $path, $name, $body);
        }
    }

    /**
     * Test that every documented example reduced to the fields its schema
     * requires is accepted by its route.
     *
     * @param  string  $method
     * @param  string  $path
     * @return void
     */
    #[DataProvider('documentedBodyProvider')]
    public function testExamplesReducedToTheirRequiredFieldsAreAcceptedByTheRoute(string $method, string $path): void
    {
        $required = $this->resolveReference($this->document, '#' . $this->requestSchemaPointer($method, $path) . '/required') ?? [];

        self::assertIsArray($required);

        foreach ($this->examplesOf($method, $path) as $name => $body) {
            $this->assertAcceptedAsDocumented($method, $path, $name . ' reduced to its required fields', Arr::only($body, $required));
        }
    }

    /**
     * Provide each JSON request body by its method, path and a body its schema
     * refuses.
     *
     * @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function refusedBodyProvider(): iterable
    {
        yield 'plain input' => ['POST', '/plain', []];
        yield 'payload' => ['POST', '/payload', []];
        yield 'form request' => ['POST', '/form-request', []];
        yield 'request schema directive' => ['POST', '/attribute', []];
        yield 'form request preparing its input against the route' => ['PUT', '/users/{user}', []];
        yield 'resource store payload' => ['POST', '/articles', []];
        yield 'resource replace payload' => ['PUT', '/articles/{article}', ['status' => 'archived']];
        yield 'resource patch payload' => ['PATCH', '/articles/{article}', ['tags' => 'news']];
    }

    /**
     * Test that a body the documented schema refuses is refused by the route
     * with the documented error.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<string, mixed>  $body
     * @return void
     */
    #[DataProvider('refusedBodyProvider')]
    public function testBodyTheSchemaRefusesIsRefusedAsDocumented(string $method, string $path, array $body): void
    {
        self::assertFalse($this->validateBody($method, $path, $body)->isValid(), 'The documented schema must refuse the body.');

        $response = $this->actingAs($this->author)->json($method, $this->uriOf($path), $body);

        $this->assertRefusedAsDocumented($method, $path, $response);
    }

    /**
     * Test that an upload is documented as multipart form data, accepted with
     * its file, and refused as documented without it.
     *
     * @return void
     */
    public function testMultipartUploadIsAcceptedWithItsFileAndRefusedAsDocumentedWithout(): void
    {
        $media = $this->requestMedia('POST', '/users/{user}/avatar');

        self::assertSame(['multipart/form-data'], array_keys($media));

        $server = ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_ACCEPT' => 'application/json'];

        $this->call('POST', $this->uriOf('/users/{user}/avatar'), [], [], ['avatar' => UploadedFile::fake()->image('avatar.png')], $server)->assertSuccessful();

        $this->assertRefusedAsDocumented('POST', '/users/{user}/avatar', $this->call('POST', $this->uriOf('/users/{user}/avatar'), [], [], [], $server));
    }

    /**
     * Assert the documented schema accepts the body and so does the route.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  string  $label
     * @param  array<array-key, mixed>  $body
     * @return void
     */
    private function assertAcceptedAsDocumented(string $method, string $path, string $label, array $body): void
    {
        $result = $this->validateBody($method, $path, $body);

        self::assertTrue($result->isValid(), sprintf('The body [%s] of %s %s must match its schema: %s', $label, $method, $path, $this->formatErrors($result)));

        $response = $this->actingAs($this->author)->json($method, $this->uriOf($path), $body)->baseResponse;

        self::assertTrue($response->isSuccessful(), sprintf(
            'The documented body [%s] of %s %s is refused with %d: %s',
            $label,
            $method,
            $path,
            $response->getStatusCode(),
            $response->getContent(),
        ));
    }

    /**
     * Assert the response is the validation failure the operation documents, in
     * a body its 422 schema accepts.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return void
     */
    private function assertRefusedAsDocumented(string $method, string $path, TestResponse $response): void
    {
        $response->assertUnprocessable();
        $response->assertJsonPath('error.code', 10106);

        $pointer  = $this->operationPointer($method, $path) . '/responses/422/content/application~1json';
        $examples = $this->resolveReference($this->document, '#' . $pointer . '/examples');

        self::assertIsArray($examples);
        self::assertSame(['$ref' => '#/components/examples/Error10106'], $examples['Error10106'] ?? null);
        self::assertSame(
            $this->resolveReference($this->document, '#/components/examples/Error10106/value/error'),
            Arr::only((array) $response->json('error'), ['status', 'code', 'title', 'detail']),
        );

        $body   = json_decode((string) $response->baseResponse->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $result = $this->validateAgainstExported($this->document, $this->uriPointer($pointer . '/schema'), $body);

        self::assertTrue($result->isValid(), 'The refusal must validate against its documented schema: ' . $this->formatErrors($result));
    }

    /**
     * Validate a body against the operation's documented JSON request schema.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<array-key, mixed>  $body
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateBody(string $method, string $path, array $body): ValidationResult
    {
        $json = json_decode(json_encode((object) $body, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        return $this->validateAgainstExported($this->document, $this->uriPointer($this->requestSchemaPointer($method, $path)), $json);
    }

    /**
     * Export the default audience as a client reads it.
     *
     * @return array<string, mixed>
     */
    private function export(): array
    {
        assert($this->app !== null);

        $document = json_decode(json_encode($this->app->make(ExportOpenApiComponents::class)->export()->document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * Read the documented examples of the operation's JSON request body, by
     * name.
     *
     * @param  string  $method
     * @param  string  $path
     * @return non-empty-array<string, array<array-key, mixed>>
     */
    private function examplesOf(string $method, string $path): array
    {
        $examples = $this->requestMedia($method, $path)['application/json']['examples'] ?? [];

        self::assertIsArray($examples);
        self::assertNotEmpty($examples, sprintf('%s %s documents no request example.', $method, $path));

        return array_map(static fn (mixed $example): array => (array) (is_array($example) ? $example['value'] : null), $examples);
    }

    /**
     * Read the content of the operation's request body, keyed by media type.
     *
     * @param  string  $method
     * @param  string  $path
     * @return array<string, mixed>
     */
    private function requestMedia(string $method, string $path): array
    {
        $content = $this->resolveReference($this->document, '#' . $this->operationPointer($method, $path) . '/requestBody/content');

        self::assertIsArray($content, sprintf('%s %s documents no request body.', $method, $path));

        /** @var array<string, mixed> $content */
        return $content;
    }

    /**
     * Build the JSON pointer of an operation.
     *
     * @param  string  $method
     * @param  string  $path
     * @return string
     */
    private function operationPointer(string $method, string $path): string
    {
        return sprintf('/paths/%s/%s', str_replace('/', '~1', $path), strtolower($method));
    }

    /**
     * Build the JSON pointer of an operation's JSON request schema.
     *
     * @param  string  $method
     * @param  string  $path
     * @return string
     */
    private function requestSchemaPointer(string $method, string $path): string
    {
        return $this->operationPointer($method, $path) . '/requestBody/content/application~1json/schema';
    }

    /**
     * Percent-encode each segment of a JSON pointer for use in a URI fragment.
     *
     * @param  string  $pointer
     * @return string
     */
    private function uriPointer(string $pointer): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $pointer)));
    }

    /**
     * Resolve the concrete URI a documented path is requested at.
     *
     * @param  string  $path
     * @return string
     */
    private function uriOf(string $path): string
    {
        return $this->uris[$path] ?? $path;
    }
}
