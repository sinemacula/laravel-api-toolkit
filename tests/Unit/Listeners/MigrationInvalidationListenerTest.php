<?php

declare(strict_types = 1);

namespace Tests\Unit\Listeners;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Exceptions\MetadataInvalidationException;
use SineMacula\ApiToolkit\Listeners\MigrationInvalidationListener;
use Tests\TestCase;

/**
 * Tests for the MigrationInvalidationListener.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MigrationInvalidationListener::class)]
final class MigrationInvalidationListenerTest extends TestCase
{
    /** @var string The opening of the warning logged when invalidation fails. */
    private const string WARNING = 'Toolkit metadata could not be invalidated after migrations: ';

    /** @var string The remedy closing the warning logged when invalidation fails. */
    private const string REMEDY = ' Run php artisan api-toolkit:invalidate-metadata once the cache store is available.';

    /**
     * Provide the migration runs that change the schema.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function schemaChangingRunProvider(): iterable
    {
        yield 'migrate' => ['up', []];
        yield 'migrate, not pretended' => ['up', ['pretend' => false]];
        yield 'rollback' => ['down', ['pretend' => false]];
    }

    /**
     * Test that a finished migration run invalidates the toolkit metadata.
     *
     * @param  string  $method
     * @param  array<string, mixed>  $options
     * @return void
     */
    #[DataProvider('schemaChangingRunProvider')]
    public function testFinishedRunInvalidatesMetadata(string $method, array $options): void
    {
        $before = $this->generation()->current();

        $this->listener()->handle(new MigrationsEnded($method, $options));

        self::assertNotSame($before, (new MetadataGeneration)->current());
    }

    /**
     * Test that a pretended run, which changes no schema, leaves the metadata
     * warm.
     *
     * @return void
     */
    public function testPretendedRunLeavesMetadataWarm(): void
    {
        $before = $this->generation()->current();

        $this->listener()->handle(new MigrationsEnded('up', ['pretend' => true]));

        self::assertSame($before, (new MetadataGeneration)->current());
    }

    /**
     * Test that a rollback which dropped the database cache store's own table
     * completes with a warning rather than failing after the fact.
     *
     * @return void
     */
    public function testRollbackThatDroppedTheCacheTableIsLoggedNotThrown(): void
    {
        Config::set('database.connections.cache_probe', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        Config::set('cache.stores.database.connection', 'cache_probe');
        Config::set('cache.default', 'database');

        Schema::connection('cache_probe')->create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::connection('cache_probe')->drop('cache');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => str_starts_with($message, self::WARNING . 'SQLSTATE')
                    && str_ends_with($message, self::REMEDY)
                    && $context['exception'] instanceof QueryException);

        event(new MigrationsEnded('down', ['pretend' => false]));
    }

    /**
     * Test that a store rejecting the new generation is logged with the command
     * to run, rather than thrown.
     *
     * @return void
     */
    public function testRejectedGenerationIsLoggedNotThrown(): void
    {
        $this->useRejectingCacheStore();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                $expected = self::WARNING . 'The cache store rejected the new metadata generation, so the cached metadata was not invalidated.' . self::REMEDY;

                return $message             === $expected
                    && array_keys($context) === ['exception']
                    && $context['exception'] instanceof MetadataInvalidationException;
            });

        $this->listener()->handle(new MigrationsEnded('up', []));
    }

    /**
     * Build the listener over the container's cache manager.
     *
     * @return \SineMacula\ApiToolkit\Listeners\MigrationInvalidationListener
     */
    private function listener(): MigrationInvalidationListener
    {
        /** @var \SineMacula\ApiToolkit\Cache\CacheManager $manager */
        $manager = $this->app->make(CacheManager::class); // @phpstan-ignore method.nonObject

        return new MigrationInvalidationListener($manager);
    }

    /**
     * Resolve the container's metadata generation.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataGeneration
     */
    private function generation(): MetadataGeneration
    {
        /** @var \SineMacula\ApiToolkit\Cache\MetadataGeneration */
        return $this->app->make(MetadataGeneration::class); // @phpstan-ignore method.nonObject
    }
}
