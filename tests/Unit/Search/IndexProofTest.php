<?php

declare(strict_types = 1);

namespace Tests\Unit\Search;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Contracts\SearchDriver;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Search\IndexProof;
use Tests\Fixtures\Search\CountingSearchDriver;
use Tests\Fixtures\Search\PatternSearchDriver;
use Tests\TestCase;

/**
 * Tests for the request-time index proof.
 *
 * The driver counts how often it was asked, so the memo and the shared answer
 * are proven by what reaches the connection rather than by what comes back from
 * it. A lifecycle boundary is crossed the way a worker crosses it: the
 * toolkit's flush, then the framework forgetting its scoped instances.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(IndexProof::class)]
final class IndexProofTest extends TestCase
{
    /** @var string The defect every refusing proof reports */
    private const string DEFECT = 'no index over the declared columns';

    /**
     * Test that the defects the driver reports per column are flattened to the
     * distinct reasons behind them, so a reason the whole set carries is stated
     * once rather than once per column.
     *
     * @return void
     */
    public function testReportsEachDistinctReasonOnce(): void
    {
        $driver = new PatternSearchDriver(null, true, [self::DEFECT]);

        self::assertSame(
            [self::DEFECT],
            $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $this->connection()),
        );
    }

    /**
     * Test that a proof with nothing to report comes back empty.
     *
     * @return void
     */
    public function testReportsNothingForADeclarationTheDriverProves(): void
    {
        self::assertSame(
            [],
            $this->proof()->defects(new PatternSearchDriver(null, true), SearchStrategy::EXACT, ['id'], 'users', $this->connection()),
        );
    }

    /**
     * Test that the answer is memoised, so the catalogue is read once within an
     * operation rather than once per search.
     *
     * @return void
     */
    public function testMemoisesTheAnswerForTheSameDeclaration(): void
    {
        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->prove($driver);

        self::assertSame(1, $driver->calls);
    }

    /**
     * Test that the answer outlives a lifecycle boundary, so the next request
     * or job serving the same schema does not read the catalogue again.
     *
     * @return void
     */
    public function testKeepsTheAnswerAcrossALifecycleBoundary(): void
    {
        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->crossBoundary();
        $this->prove($driver);

        self::assertSame(1, $driver->calls);
    }

    /**
     * Test that a refusal outlives a lifecycle boundary too, so a missing index
     * keeps refusing without the catalogue being read again.
     *
     * @return void
     */
    public function testKeepsARefusalAcrossALifecycleBoundary(): void
    {
        $driver          = new CountingSearchDriver;
        $driver->defects = ['name' => [self::DEFECT]];

        $this->prove($driver);
        $this->crossBoundary();

        $driver->defects = [];

        self::assertSame([self::DEFECT], $this->prove($driver));
        self::assertSame(1, $driver->calls);
    }

    /**
     * Test that the shared answer is still served just before its expiry.
     *
     * @return void
     */
    public function testKeepsTheAnswerUntilItsExpiry(): void
    {
        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->crossBoundary();
        $this->travel(IndexProof::DEFAULT_TTL - 1)->seconds();
        $this->prove($driver);

        self::assertSame(1, $driver->calls);
    }

    /**
     * Test that the shared answer is read again once its expiry lapses, which
     * is what bounds an index changed outside a migration.
     *
     * @return void
     */
    public function testReadsTheCatalogueAgainOnceTheExpiryLapses(): void
    {
        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->crossBoundary();
        $this->travel(IndexProof::DEFAULT_TTL + 1)->seconds();
        $this->prove($driver);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Provide the forms a configured expiry of ten seconds arrives in.
     *
     * @return iterable<string, array{0: int|string}>
     */
    public static function tenSecondExpiries(): iterable
    {
        yield 'integer' => [10];
        yield 'environment string' => ['10'];
    }

    /**
     * Test that the configured expiry is the one applied, whether it arrives as
     * a number or as the string the environment carries.
     *
     * @param  int|string  $ttl
     * @return void
     */
    #[DataProvider('tenSecondExpiries')]
    public function testAppliesTheConfiguredExpiry(int|string $ttl): void
    {
        Config::set(IndexProof::TTL_KEY, $ttl);

        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->crossBoundary();
        $this->travel(9)->seconds();
        $this->prove($driver);

        self::assertSame(1, $driver->calls);

        $this->crossBoundary();
        $this->travel(2)->seconds();
        $this->prove($driver);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that reading the shared answer does not extend its expiry, so an
     * index proof asked for continuously still reads the catalogue again.
     *
     * @return void
     */
    public function testReadingTheAnswerDoesNotExtendItsExpiry(): void
    {
        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->crossBoundary();
        $this->travel(40)->seconds();
        $this->prove($driver);
        $this->crossBoundary();
        $this->travel(21)->seconds();
        $this->prove($driver);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that invalidating the metadata retires a stored refusal, so an index
     * a migration creates is proved by the next search.
     *
     * @return void
     */
    public function testInvalidationRetiresAStoredRefusal(): void
    {
        $driver          = new CountingSearchDriver;
        $driver->defects = ['name' => [self::DEFECT]];

        self::assertSame([self::DEFECT], $this->prove($driver));

        $this->manager()->invalidateMetadata();

        $driver->defects = [];

        self::assertSame([], $this->prove($driver));
        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that invalidating the metadata retires a stored acceptance, so an
     * index a migration drops is refused by the next search.
     *
     * @return void
     */
    public function testInvalidationRetiresAStoredAcceptance(): void
    {
        $driver = new CountingSearchDriver;

        self::assertSame([], $this->prove($driver));

        $this->manager()->invalidateMetadata();

        $driver->defects = ['name' => [self::DEFECT]];

        self::assertSame([self::DEFECT], $this->prove($driver));
        self::assertSame(2, $driver->calls);
    }

    /**
     * Provide declarations differing from the proved one in a single part.
     *
     * @return iterable<string, array{0: string, 1: \SineMacula\ApiToolkit\Enums\SearchStrategy, 2: array<int, string>}>
     */
    public static function distinctDeclarations(): iterable
    {
        yield 'table' => ['articles', SearchStrategy::SUBSTRING, ['name', 'email']];
        yield 'strategy' => ['users', SearchStrategy::PREFIX, ['name', 'email']];
        yield 'columns' => ['users', SearchStrategy::SUBSTRING, ['name']];
        yield 'column order' => ['users', SearchStrategy::SUBSTRING, ['email', 'name']];
    }

    /**
     * Test that a declaration differing in any part of its key is proved on its
     * own, so one declaration's shared answer never stands in for another's.
     *
     * @param  string  $table
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $columns
     * @return void
     */
    #[DataProvider('distinctDeclarations')]
    public function testProvesEachDistinctDeclarationSeparately(string $table, SearchStrategy $strategy, array $columns): void
    {
        $driver     = new CountingSearchDriver;
        $connection = $this->connection();

        $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name', 'email'], 'users', $connection);
        $this->crossBoundary();
        $this->proof()->defects($driver, $strategy, $columns, $table, $connection);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that another driver serving the same declaration proves it on its
     * own, so one driver's answer never stands in for another's.
     *
     * @return void
     */
    public function testProvesTheSameDeclarationAgainForAnotherDriver(): void
    {
        $driver = new CountingSearchDriver;

        $this->prove(new PatternSearchDriver(null, true, [self::DEFECT]));
        $this->crossBoundary();

        self::assertSame([], $this->prove($driver));
        self::assertSame(1, $driver->calls);
    }

    /**
     * Test that a change to the shortest word a term may carry proves the
     * declaration again, since one engine's proof compares its token size with
     * that length.
     *
     * @return void
     */
    public function testProvesAgainOnceTheShortestWordChanges(): void
    {
        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->crossBoundary();

        Config::set('api-toolkit.search.min_word_length', 4);

        $this->prove($driver);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Provide the ways a tenancy switcher repoints one connection name.
     *
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function tenantRepointings(): iterable
    {
        yield 'database' => [['database' => 'tenant_b']];
        yield 'table prefix' => [['prefix' => 'tenant_b_']];
        yield 'search path' => [['search_path' => 'tenant_b']];
    }

    /**
     * Test that one connection name repointed at another tenant's schema is
     * proved again, so one tenant's shared answer never answers for another's.
     *
     * @param  array<string, string>  $change
     * @return void
     */
    #[DataProvider('tenantRepointings')]
    public function testProvesARepointedConnectionAgain(array $change): void
    {
        $driver = new CountingSearchDriver;

        Config::set('database.connections.tenant', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', DB::connection('tenant'));
        $this->crossBoundary();

        Config::set('database.connections.tenant', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', ...$change]);
        DB::purge('tenant');

        $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', DB::connection('tenant'));

        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that the proof follows the connection it is handed rather than the
     * one registered under its name, so a resolver handing out another tenant's
     * connection under the same name is proved again.
     *
     * @return void
     */
    public function testProvesTheConnectionItIsHandedRatherThanItsName(): void
    {
        $driver     = new CountingSearchDriver;
        $registered = $this->connection();
        $name       = (string) $registered->getName();

        $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', $registered);
        $this->crossBoundary();

        $handed = new SQLiteConnection(static fn (): \PDO => new \PDO('sqlite::memory:'), 'tenant_b', '', ['name' => $name]);

        $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', $handed);

        self::assertSame($name, $handed->getName());
        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that an expiry of zero shares nothing, so the store is never written
     * and the next operation reads the catalogue again.
     *
     * @return void
     */
    public function testAnExpiryOfZeroSharesNothing(): void
    {
        Config::set(IndexProof::TTL_KEY, 0);

        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->prove($driver);

        self::assertSame(1, $driver->calls);
        self::assertSame([], $this->storedProofs());

        $this->crossBoundary();
        $this->prove($driver);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that an expiry of zero never reaches the shared store at all, so a
     * deployment that switched sharing off pays no store round trip for it.
     *
     * @return void
     */
    public function testAnExpiryOfZeroNeverReachesTheStore(): void
    {
        Config::set(IndexProof::TTL_KEY, 0);

        $keys = [];

        Event::listen([RetrievingKey::class, WritingKey::class, ForgettingKey::class], static function (CacheEvent $event) use (&$keys): void {
            $keys[] = $event->key;
        });

        $this->prove(new CountingSearchDriver);

        self::assertSame([], array_values(array_filter($keys, static fn (string $key): bool => str_contains($key, 'search-index-proof:'))));
    }

    /**
     * Test that the same connection on another engine is proved again, since
     * the schema identity does not name the engine behind the connection.
     *
     * @return void
     */
    public function testProvesTheSameConnectionAgainOnAnotherEngine(): void
    {
        $driver = new CountingSearchDriver;
        $config = ['name' => 'tenant', 'database' => 'app', 'prefix' => ''];
        $pdo    = static fn (): \PDO => new \PDO('sqlite::memory:');

        $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', new SQLiteConnection($pdo, 'app', '', [...$config, 'driver' => 'sqlite']));
        $this->crossBoundary();
        $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', new PostgresConnection($pdo, 'app', '', [...$config, 'driver' => 'pgsql']));

        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that a proof is written to the shared store under the proof's own
     * key, which is what the expiry of zero is measured against.
     *
     * @return void
     */
    public function testStoresTheAnswerUnderTheProofKey(): void
    {
        $this->prove(new CountingSearchDriver);

        self::assertCount(1, $this->storedProofs());
    }

    /**
     * Provide configured expiries that are not a usable number of seconds.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function unusableExpiries(): iterable
    {
        yield 'negative' => [-5];
        yield 'negative string' => ['-1'];
        yield 'negative fraction' => ['-0.5'];
        yield 'non-numeric' => ['soon'];
        yield 'null' => [null];
    }

    /**
     * Test that an unusable configured expiry falls back to the default rather
     * than sharing nothing or sharing forever.
     *
     * @param  mixed  $ttl
     * @return void
     */
    #[DataProvider('unusableExpiries')]
    public function testAnUnusableExpiryFallsBackToTheDefault(mixed $ttl): void
    {
        Config::set(IndexProof::TTL_KEY, $ttl);

        $driver = new CountingSearchDriver;

        $this->prove($driver);
        $this->crossBoundary();
        $this->travel(IndexProof::DEFAULT_TTL - 1)->seconds();
        $this->prove($driver);

        self::assertSame(1, $driver->calls);

        $this->crossBoundary();
        $this->travel(2)->seconds();
        $this->prove($driver);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that a proof the driver could not take is neither memoised nor
     * shared, so the failure reaches the caller and the next search asks again.
     *
     * @return void
     */
    public function testAFailedProofIsNotKept(): void
    {
        $failure         = new \RuntimeException('catalogue unavailable');
        $driver          = new CountingSearchDriver;
        $driver->failure = $failure;

        try {
            $this->prove($driver);

            self::fail('The failed proof did not reach the caller.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame([], $this->storedProofs());

        $driver->failure = null;

        self::assertSame([], $this->prove($driver));
        self::assertSame(2, $driver->calls);
    }

    /**
     * Test that clearing the memo reads the connection again when nothing is
     * shared, which is what a cache flush has to guarantee there.
     *
     * @return void
     */
    public function testClearingTheMemoReadsTheConnectionAgainWhenNothingIsShared(): void
    {
        Config::set(IndexProof::TTL_KEY, 0);

        $driver = new CountingSearchDriver;

        $this->prove($driver);

        IndexProof::clearCache();

        $this->prove($driver);

        self::assertSame(2, $driver->calls);
    }

    /**
     * Take the proof of the declaration most tests share.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SearchDriver  $driver
     * @return array<int, string>
     */
    private function prove(SearchDriver $driver): array
    {
        return $this->proof()->defects($driver, SearchStrategy::SUBSTRING, ['name'], 'users', $this->connection());
    }

    /**
     * Resolve the index proof under test.
     *
     * @return \SineMacula\ApiToolkit\Search\IndexProof
     */
    private function proof(): IndexProof
    {
        assert($this->app !== null);

        return $this->app->make(IndexProof::class);
    }

    /**
     * Resolve the cache manager a lifecycle boundary runs through.
     *
     * @return \SineMacula\ApiToolkit\Cache\CacheManager
     */
    private function manager(): CacheManager
    {
        assert($this->app !== null);

        return $this->app->make(CacheManager::class);
    }

    /**
     * Cross a lifecycle boundary the way a worker does.
     *
     * @return void
     */
    private function crossBoundary(): void
    {
        assert($this->app !== null);

        $this->manager()->flush();
        $this->app->forgetScopedInstances();
    }

    /**
     * Return the index proofs the shared store holds, keyed by storage key.
     *
     * @return array<string, mixed>
     */
    private function storedProofs(): array
    {
        $store = Cache::store()->getStore();

        assert($store instanceof ArrayStore);

        return array_filter(
            $store->all(),
            static fn (string $key): bool => str_contains($key, 'search-index-proof:'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Return the connection the suite runs against.
     *
     * @return \Illuminate\Database\Connection
     */
    private function connection(): Connection
    {
        return DB::connection();
    }
}
