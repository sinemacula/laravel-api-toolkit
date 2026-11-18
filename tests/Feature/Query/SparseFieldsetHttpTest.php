<?php

declare(strict_types = 1);

namespace Tests\Feature\Query;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Concerns\QuerySelectionValidator;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for sparse fieldset resolution over the real HTTP pipeline.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversClass(ApiResourceCollection::class)]
#[CoversClass(FieldResolver::class)]
#[CoversClass(ValueResolver::class)]
#[CoversClass(QueryParameterValidator::class)]
#[CoversClass(QuerySelectionValidator::class)]
final class SparseFieldsetHttpTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /**
     * Set up each test with a repository-backed users route and seeded data.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, PostResource::class]);

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', function (UserRepository $repository): ApiResourceCollection {

            $users = $repository->usingResource(UserResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($users, UserResource::class);
        });

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        Post::create(['user_id' => $alice->id, 'title' => 'First', 'body' => 'Content', 'published' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Second', 'body' => 'Content', 'published' => false]);
    }

    /**
     * Test that the `:all` token expands to every declared non-metric field.
     *
     * @return void
     */
    public function testAllTokenExpandsToEveryDeclaredField(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => ':all'],
        ]));

        $response->assertOk();

        /** @var array<string, mixed> $record */
        $record = $response->json('data.0');

        foreach (['id', '_type', 'name', 'email', 'status', 'created_at', 'updated_at', 'full_label', 'display_label'] as $field) {
            self::assertArrayHasKey($field, $record);
        }
    }

    /**
     * Test that the default field set, without aggregate buckets, applies when
     * fields is omitted.
     *
     * @return void
     */
    public function testDefaultFieldSetAppliesWhenFieldsOmitted(): void
    {
        $response = $this->getJson('/users');

        $response->assertOk();

        /** @var array<string, mixed> $record */
        $record = $response->json('data.0');

        foreach (['id', '_type', 'name', 'email'] as $field) {
            self::assertArrayHasKey($field, $record);
        }

        foreach (['status', 'created_at', 'updated_at', 'full_label', 'display_label', 'organization', 'posts', 'aggregates'] as $field) {
            self::assertArrayNotHasKey($field, $record);
        }
    }

    /**
     * Test that computed and accessor fields surface with their closure output.
     *
     * @return void
     */
    public function testComputedAndAccessorFieldsSurface(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'full_label,display_label'],
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.0.full_label', 'Alice <alice@example.com>');
        $response->assertJsonPath('data.0.display_label', 'Alice <alice@example.com>');
    }

    /**
     * Provide selections naming a type or a name the server does not declare,
     * or not keyed by type, with the errors they are rejected with.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, array<int, string>>}>
     */
    public static function rejectedSelectionProvider(): iterable
    {
        yield 'misspelt field' => [['fields' => ['users' => 'name,emial']], ['fields.users' => ['The "emial" field is not declared on the "users" resource type.']]];
        yield 'singular type' => [['fields' => ['user' => 'name']], ['fields.user' => ['The "user" resource type is not known.']]];
        yield 'capitalised type' => [['fields' => ['Users' => 'name']], ['fields.Users' => ['The "Users" resource type is not known.']]];
        yield 'misspelt aggregate' => [['aggregates' => ['users' => 'post']], ['aggregates.users' => ['The "post" aggregate is not declared on the "users" resource type.']]];
        yield 'flat fields' => [['fields' => 'name'], ['fields' => ['The fields parameter must be keyed by resource type, e.g. fields[users]=name.']]];
        yield 'listed fields' => [['fields' => ['name']], ['fields.0' => ['The "0" resource type is not known.']]];
        yield 'flat aggregates' => [
            ['aggregates' => 'posts_count'],
            ['aggregates' => ['The aggregates parameter must be keyed by resource type, e.g. aggregates[users]=posts_count.']],
        ];
        yield 'listed aggregates' => [['aggregates' => ['posts_count']], ['aggregates.0' => ['The "0" resource type is not known.']]];
        yield 'every unknown at once' => [
            ['fields' => ['users' => 'emial,nmae', 'user' => 'name'], 'aggregates' => ['users' => 'post']],
            [
                'fields.users' => [
                    'The "emial" field is not declared on the "users" resource type.',
                    'The "nmae" field is not declared on the "users" resource type.',
                ],
                'fields.user'      => ['The "user" resource type is not known.'],
                'aggregates.users' => ['The "post" aggregate is not declared on the "users" resource type.'],
            ],
        ];
    }

    /**
     * Test that an unknown or unkeyed selection is rejected with a 422 naming
     * it before any query runs.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, array<int, string>>  $errors
     * @return void
     */
    #[DataProvider('rejectedSelectionProvider')]
    public function testUnknownSelectionIsRejectedBeforeAnyQuery(array $query, array $errors): void
    {
        $statements = $this->recordStatements(function () use ($query, $errors): void {

            $response = $this->getJson('/users?' . http_build_query($query));

            $response->assertUnprocessable();

            self::assertSame($errors, $response->json('error.meta'));
        });

        self::assertStatementCount(0, $statements);
    }

    /**
     * Test that a fieldset for a type the endpoint does not render is valid, so
     * one fieldset map serves every endpoint.
     *
     * @return void
     */
    public function testFieldsetForATypeTheEndpointDoesNotRenderIsAccepted(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'name', 'posts' => 'title'],
        ]));

        $response->assertOk();

        self::assertSame(['_type', 'id', 'name'], array_keys((array) $response->json('data.0')));
    }
}
