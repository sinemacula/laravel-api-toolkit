<?php

declare(strict_types = 1);

namespace Tests\Feature\Caching;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\TenantUser;
use Tests\Fixtures\Repositories\CacheableTenantUserRepository;
use Tests\Fixtures\Support\CurrentTenant;
use Tests\TestCase;

/**
 * Feature tests proving a cacheable repository read over a model with a tenant
 * global scope is cached per tenant.
 *
 * The scope reads the bound tenant only when the query runs, so a key taken
 * before the scopes apply would serve one tenant's rows to another.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
final class CacheableGlobalScopeTest extends TestCase
{
    /** @var array<string, int> The organization of each tenant, by tenant name */
    private array $tenants = [];

    /** @var int The row only the tenant named one can see */
    private int $rowOfTenantOne;

    /**
     * Set up one row in each of two tenants.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');

        $rows = [];

        foreach (['one', 'two'] as $name) {
            $this->tenants[$name] = Organization::create(['name' => $name, 'slug' => $name])->id;
            $rows[$name]          = TenantUser::create(['name' => $name, 'email' => $name . '@example.com', 'organization_id' => $this->tenants[$name]])->id;
        }

        $this->rowOfTenantOne = $rows['one'];
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        DB::disableQueryLog();

        parent::tearDown();
    }

    /**
     * Provide both orders in which the two tenants can read first.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function tenantOrders(): iterable
    {
        yield 'tenant one first' => ['one', 'two'];
        yield 'tenant two first' => ['two', 'one'];
    }

    /**
     * Test that each tenant's get() returns its own rows whichever tenant reads
     * first.
     *
     * @param  string  $first
     * @param  string  $second
     * @return void
     */
    #[DataProvider('tenantOrders')]
    public function testGetReturnsEachTenantItsOwnRows(string $first, string $second): void
    {
        self::assertSame([$first], $this->namesAs($first));
        self::assertSame([$second], $this->namesAs($second));
    }

    /**
     * Test that only the owning tenant can find a row, whichever tenant reads
     * it first.
     *
     * @param  string  $first
     * @param  string  $second
     * @return void
     */
    #[DataProvider('tenantOrders')]
    public function testFindReturnsNullForAnotherTenantsRow(string $first, string $second): void
    {
        $found = [$first => $this->findAs($first), $second => $this->findAs($second)];

        self::assertNotNull($found['one']);
        self::assertNull($found['two']);
    }

    /**
     * Test that a repeated read for the same tenant is served from the cache
     * with no queries.
     *
     * @return void
     */
    public function testRepeatedReadForTheSameTenantIsServedWithZeroQueries(): void
    {
        $this->namesAs('one');

        DB::enableQueryLog();

        self::assertSame(['one'], $this->namesAs('one'));
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Read every row's name as the given tenant.
     *
     * @param  string  $tenant
     * @return array<int, mixed>
     */
    private function namesAs(string $tenant): array
    {
        app()->instance(CurrentTenant::class, new CurrentTenant($this->tenants[$tenant]));

        return app(CacheableTenantUserRepository::class)->get()->pluck('name')->all(); // @phpstan-ignore staticMethod.dynamicCall, method.nonObject
    }

    /**
     * Find the row of the tenant named one as the given tenant.
     *
     * @param  string  $tenant
     * @return mixed
     */
    private function findAs(string $tenant): mixed
    {
        app()->instance(CurrentTenant::class, new CurrentTenant($this->tenants[$tenant]));

        return app(CacheableTenantUserRepository::class)->find($this->rowOfTenantOne); // @phpstan-ignore staticMethod.dynamicCall
    }
}
