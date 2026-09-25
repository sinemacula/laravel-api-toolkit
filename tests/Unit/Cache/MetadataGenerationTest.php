<?php

declare(strict_types = 1);

namespace Tests\Unit\Cache;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Exceptions\MetadataInvalidationException;
use Tests\TestCase;

/**
 * Tests for the MetadataGeneration shared namespace.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MetadataGeneration::class)]
final class MetadataGenerationTest extends TestCase
{
    /** @var string The shape every minted generation token takes. */
    private const string TOKEN_PATTERN = '/^[A-Za-z0-9]{16}$/';

    /**
     * Test that a store holding no generation gets one minted and kept, so
     * every later process reads the same namespace.
     *
     * @return void
     */
    public function testCurrentMintsAndStoresAGenerationWhenTheStoreHoldsNone(): void
    {
        Cache::forget($this->generationKey());

        $current = (new MetadataGeneration)->current();

        self::assertMatchesRegularExpression(self::TOKEN_PATTERN, $current);
        self::assertSame($current, Cache::get($this->generationKey()));
        self::assertSame($current, (new MetadataGeneration)->current());
    }

    /**
     * Test that a generation already in the store is adopted as it stands.
     *
     * @return void
     */
    public function testCurrentAdoptsTheGenerationAlreadyStored(): void
    {
        Cache::forever($this->generationKey(), 'stored-elsewhere');

        self::assertSame('stored-elsewhere', (new MetadataGeneration)->current());
    }

    /**
     * Test that the generation is read once and held until forgotten, so a
     * process does not go back to the store for every metadata key.
     *
     * @return void
     */
    public function testCurrentIsMemoisedUntilForgotten(): void
    {
        Cache::forever($this->generationKey(), 'first');

        $generation = new MetadataGeneration;

        self::assertSame('first', $generation->current());

        Cache::forever($this->generationKey(), 'second');

        self::assertSame('first', $generation->current());

        $generation->forget();

        self::assertSame('second', $generation->current());
    }

    /**
     * Provide stored values that cannot serve as a generation.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unusableValueProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'integer' => [7];
    }

    /**
     * Test that a stored value that is not a usable token is replaced rather
     * than trusted as a namespace.
     *
     * @param  mixed  $unusable
     * @return void
     */
    #[DataProvider('unusableValueProvider')]
    public function testCurrentReplacesAnUnusableStoredValue(mixed $unusable): void
    {
        Cache::forever($this->generationKey(), $unusable);

        $current = (new MetadataGeneration)->current();

        self::assertMatchesRegularExpression(self::TOKEN_PATTERN, $current);
        self::assertSame($current, Cache::get($this->generationKey()));
    }

    /**
     * Test that a process which lost the race to mint the generation adopts the
     * token the winner stored rather than its own.
     *
     * @return void
     */
    public function testCurrentAdoptsTheTokenARacingProcessStoredFirst(): void
    {
        $key = $this->generationKey();

        Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, 'winner');
        Cache::shouldReceive('add')->with($key, \Mockery::pattern(self::TOKEN_PATTERN))->once()->andReturn(false);

        self::assertSame('winner', (new MetadataGeneration)->current());
    }

    /**
     * Test that a store which keeps nothing still yields a usable generation
     * for the life of the process.
     *
     * @return void
     */
    public function testCurrentFallsBackToItsOwnTokenWhenTheStoreKeepsNothing(): void
    {
        Config::set('cache.stores.none', ['driver' => 'null']);
        Config::set('cache.default', 'none');

        $generation = new MetadataGeneration;
        $current    = $generation->current();

        self::assertMatchesRegularExpression(self::TOKEN_PATTERN, $current);
        self::assertSame($current, $generation->current());
    }

    /**
     * Test that advancing stores a fresh generation forever and adopts it.
     *
     * @return void
     */
    public function testAdvanceStoresAFreshGeneration(): void
    {
        $generation = new MetadataGeneration;
        $before     = $generation->current();

        $advanced = $generation->advance();

        self::assertMatchesRegularExpression(self::TOKEN_PATTERN, $advanced);
        self::assertNotSame($before, $advanced);
        self::assertSame($advanced, $generation->current());
        self::assertSame($advanced, Cache::get($this->generationKey()));
    }

    /**
     * Test that advancing writes the generation without an expiry, so it cannot
     * lapse and silently turn every metadata entry cold.
     *
     * @return void
     */
    public function testAdvanceWritesTheGenerationForever(): void
    {
        Cache::shouldReceive('forever')
            ->with($this->generationKey(), \Mockery::pattern(self::TOKEN_PATTERN))
            ->once()
            ->andReturn(true);

        self::assertMatchesRegularExpression(self::TOKEN_PATTERN, (new MetadataGeneration)->advance());
    }

    /**
     * Test that a store rejecting the new generation is surfaced, and the
     * process stays on the generation it already held.
     *
     * @return void
     */
    public function testAdvanceThrowsWhenTheStoreRejectsTheGeneration(): void
    {
        $this->useRejectingCacheStore();

        $generation = new MetadataGeneration;
        $before     = $generation->current();

        try {
            $generation->advance();
            self::fail('A rejected generation was reported as advanced.');
        } catch (MetadataInvalidationException $exception) {
            self::assertSame('The cache store rejected the new metadata generation, so the cached metadata was not invalidated.', $exception->getMessage());
        }

        self::assertSame($before, $generation->current());
    }

    /**
     * Provide the cache stores the generation is proven against.
     *
     * @return iterable<string, array{string}>
     */
    public static function storeProvider(): iterable
    {
        yield 'array' => ['array'];
        yield 'file' => ['file'];
        yield 'database' => ['database'];
        yield 'redis' => ['redis'];
    }

    /**
     * Test that metadata written under one generation is unreachable from a
     * fresh process once another process has advanced it, on every store the
     * toolkit supports.
     *
     * Each process has its own registry and generation memo, as separate PHP
     * processes would; only the store is shared.
     *
     * @param  string  $store
     * @return void
     */
    #[DataProvider('storeProvider')]
    public function testAdvancingRetiresMetadataAcrossProcessesOnEachStore(string $store): void
    {
        $this->useStore($store);

        $key    = 'store-shape-probe';
        $before = (new MetadataGeneration)->current();
        $after  = null;

        try {
            $this->process()->rememberMetadataForever($key, fn (): string => 'before');

            self::assertSame('before', $this->process()->readMetadata($key));

            $after  = (new MetadataGeneration)->advance();
            $reader = $this->process();

            self::assertNull($reader->readMetadata($key));
            self::assertSame('after', $reader->rememberMetadataForever($key, fn (): string => 'after'));
            self::assertSame('after', $this->process()->readMetadata($key));
        } finally {
            Cache::forget($key . ':' . $before);
            Cache::forget($key . ':' . $after);
            Cache::forget($this->generationKey());
        }
    }

    /**
     * Build a writer as a separate process would hold it: its own registry and
     * its own generation memo over the shared store.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    private function process(): MetadataCacheWriter
    {
        return new MetadataCacheWriter(new MetadataKeyRegistry, new MetadataGeneration);
    }

    /**
     * Point the default cache store at the given store, preparing its backing
     * where it needs one.
     *
     * @param  string  $store
     * @return void
     */
    private function useStore(string $store): void
    {
        if ($store === 'database') {
            $this->prepareDatabaseStore();
        }

        if ($store === 'redis') {
            $this->prepareRedisStore();
        }

        Config::set('cache.default', $store);
    }

    /**
     * Give the database store a table of its own on a private connection, so
     * the probe never touches the suite's database.
     *
     * @return void
     */
    private function prepareDatabaseStore(): void
    {
        Config::set('database.connections.cache_probe', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('cache.stores.database.connection', 'cache_probe');

        Schema::connection('cache_probe')->create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    }

    /**
     * Isolate the redis probe under a key prefix of its own, or skip where no
     * redis server answers.
     *
     * @return void
     */
    private function prepareRedisStore(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('The redis store needs the redis extension.');
        }

        Config::set('api-toolkit.cache.prefix', uniqid('api-toolkit-probe-', true));

        try {
            Cache::store('redis')->get('probe');
        } catch (\Throwable) {
            self::markTestSkipped('No redis server answered, so the redis store cannot be proven here.');
        }
    }

    /**
     * Return the store key the generation is held under.
     *
     * @return string
     */
    private function generationKey(): string
    {
        return CacheKeys::METADATA_GENERATION->resolveKey();
    }
}
