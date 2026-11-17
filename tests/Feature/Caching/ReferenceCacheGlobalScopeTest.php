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
use Tests\Fixtures\Repositories\ReferenceCacheTenantUserRepository;
use Tests\Fixtures\Support\CurrentTenant;
use Tests\TestCase;

/**
 * Feature tests proving a reference-mode repository over a model with a tenant
 * global scope keeps a snapshot per tenant, and that one write clears them all.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiRepository::class)]
final class ReferenceCacheGlobalScopeTest extends TestCase
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
     * Test that each tenant is served its own rows from the snapshot, whichever
     * tenant reads first.
     *
     * @param  string  $first
     * @param  string  $second
     * @return void
     */
    #[DataProvider('tenantOrders')]
    public function testEachTenantIsServedItsOwnRows(string $first, string $second): void
    {
        self::assertSame([$first], $this->namesAs($first));
        self::assertSame([$second], $this->namesAs($second));

        $found = [$first => $this->findAs($first), $second => $this->findAs($second)];

        self::assertNotNull($found['one']);
        self::assertNull($found['two']);
    }

    /**
     * Test that each tenant's repeated read is served from its snapshot with no
     * queries.
     *
     * @return void
     */
    public function testRepeatedReadsAreServedFromEachTenantsSnapshot(): void
    {
        $this->namesAs('one');
        $this->namesAs('two');

        DB::enableQueryLog();

        self::assertSame(['one'], $this->namesAs('one'));
        self::assertSame(['two'], $this->namesAs('two'));
        self::assertCount(0, DB::getQueryLog());
    }

    /**
     * Test that a write through the repository clears every tenant's snapshot.
     *
     * @return void
     */
    public function testWriteThroughTheRepositoryClearsEveryTenantsSnapshot(): void
    {
        $this->namesAs('one');
        $this->namesAs('two');

        $attributes = ['name' => 'one-new', 'email' => 'one-new@example.com', 'organization_id' => $this->tenants['one']];

        $this->repositoryAs('one')->create($attributes); // @phpstan-ignore staticMethod.dynamicCall

        DB::enableQueryLog();

        self::assertSame(['one', 'one-new'], $this->namesAs('one'));
        self::assertCount(1, DB::getQueryLog());

        DB::flushQueryLog();

        self::assertSame(['two'], $this->namesAs('two'));
        self::assertCount(1, DB::getQueryLog());
    }

    /**
     * Read every row's name as the given tenant.
     *
     * @param  string  $tenant
     * @return array<int, mixed>
     */
    private function namesAs(string $tenant): array
    {
        return $this->repositoryAs($tenant)->get()->pluck('name')->all(); // @phpstan-ignore staticMethod.dynamicCall, method.nonObject
    }

    /**
     * Find the row of the tenant named one as the given tenant.
     *
     * @param  string  $tenant
     * @return mixed
     */
    private function findAs(string $tenant): mixed
    {
        return $this->repositoryAs($tenant)->find($this->rowOfTenantOne); // @phpstan-ignore staticMethod.dynamicCall
    }

    /**
     * Bind the given tenant and resolve the reference repository.
     *
     * @param  string  $tenant
     * @return \Tests\Fixtures\Repositories\ReferenceCacheTenantUserRepository
     */
    private function repositoryAs(string $tenant): ReferenceCacheTenantUserRepository
    {
        app()->instance(CurrentTenant::class, new CurrentTenant($this->tenants[$tenant]));

        return app(ReferenceCacheTenantUserRepository::class);
    }
}
