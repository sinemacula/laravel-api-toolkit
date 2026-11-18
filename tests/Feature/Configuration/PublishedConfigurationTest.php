<?php

declare(strict_types = 1);

namespace Tests\Feature\Configuration;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Config\ToolkitConfig;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use Tests\Concerns\RecordsStatements;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Feature tests serving requests on a configuration published before some
 * shipped settings existed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ToolkitConfig::class)]
#[CoversClass(ApiServiceProvider::class)]
final class PublishedConfigurationTest extends TestCase
{
    use RecordsStatements, RegistersApiExceptionHandler;

    /**
     * Test that a resources section published by the previous major keeps
     * column narrowing and dynamic morph mapping on, as shipped.
     *
     * @return void
     */
    public function testAResourcesSectionPublishedBeforeTheirSettingsKeepsTheShippedBehaviour(): void
    {
        assert($this->app !== null);

        Config::set('api-toolkit.resources', [
            'resource_map' => [User::class => UserResource::class],
            'fixed_fields' => ['id', '_type'],
        ]);

        $this->app->register(ApiServiceProvider::class, true);
        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/users', static fn (UserRepository $repository): ApiResourceCollection => new ApiResourceCollection(
            $repository->usingResource(UserResource::class)->withApiCriteria()->paginate(),
            UserResource::class,
        ));

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret', 'status' => 'active']);

        $statements = $this->recordStatements(fn () => $this->getJson('/users?fields[users]=name')->assertOk()->assertJsonPath('data.0.name', 'Alice'));

        $select = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'select') && !str_contains($sql, 'count(')));

        self::assertNotSame([], $select);
        self::assertStringNotContainsString('*', $select[0]);
        self::assertStringNotContainsString('password', $select[0]);
        self::assertSame(User::class, Relation::getMorphedModel('users'));
    }
}
