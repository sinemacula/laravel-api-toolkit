<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests for sparse fieldset resolution over the real HTTP pipeline.
 *
 * Drives the middleware plus repository pagination path so field selection is
 * proven on the wire: the `:all` token expands to every declared field, the
 * default set applies when the fields parameter is omitted, computed and
 * accessor fields surface with their closure output, and an undeclared
 * requested field is silently dropped rather than rejected.
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
final class SparseFieldsetHttpTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a repository-backed users route and seeded data.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

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
     * Test that the default field set applies when the fields parameter is
     * omitted, exposing only the default fields while withholding non-default
     * declared fields and the aggregate buckets whose pseudo-fields are absent
     * from the default set.
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

        foreach (['status', 'created_at', 'updated_at', 'full_label', 'display_label', 'organization', 'posts', 'counts', 'sums', 'averages'] as $field) {
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
     * Test that an undeclared requested field is silently dropped rather than
     * rejected with an error envelope.
     *
     * @return void
     */
    public function testUndeclaredFieldIsSilentlyDropped(): void
    {
        $response = $this->getJson('/users?' . http_build_query([
            'fields' => ['users' => 'name,not_a_real_field'],
        ]));

        $response->assertOk();

        /** @var array<string, mixed> $record */
        $record = $response->json('data.0');

        self::assertArrayHasKey('name', $record);
        self::assertArrayHasKey('id', $record);
        self::assertArrayHasKey('_type', $record);
        self::assertArrayNotHasKey('not_a_real_field', $record);
        self::assertArrayNotHasKey('email', $record);
        self::assertNull($response->json('error'));
    }
}
