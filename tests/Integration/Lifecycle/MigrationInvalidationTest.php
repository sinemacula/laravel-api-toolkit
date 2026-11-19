<?php

declare(strict_types = 1);

namespace Tests\Integration\Lifecycle;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\CacheManager;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Listeners\MigrationInvalidationListener;
use SineMacula\ApiToolkit\Providers\Registrars\LifecycleRegistrar;
use Tests\TestCase;

/**
 * End-to-end tests for invalidating metadata when a real migration run ends.
 *
 * Migrations run on a private in-memory connection so the suite's database is
 * untouched.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheManager::class)]
#[CoversClass(LifecycleRegistrar::class)]
#[CoversClass(MetadataGeneration::class)]
#[CoversClass(MigrationInvalidationListener::class)]
final class MigrationInvalidationTest extends TestCase
{
    /** @var string The connection the probe migrations run against. */
    private const string CONNECTION = 'migration_probe';

    /** @var string The metadata key the earlier process writes. */
    private const string KEY = 'migration-probe-metadata';

    /** @var string The name of the probe migration. */
    private const string MIGRATION = '2026_01_01_000000_create_probe_table';

    /** @var string|null The directory holding the probe migration. */
    private ?string $migrationPath = null;

    /**
     * Prepare the probe connection and migration.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.' . self::CONNECTION, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        $this->migrationPath = sys_get_temp_dir() . '/api-toolkit-migration-probe-' . getmypid() . '-' . uniqid('', true);

        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($this->migrationPath);
        $filesystem->put($this->migrationPath . '/' . self::MIGRATION . '.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('probe', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('probe');
                }
            };
            PHP);

        $filesystem->put($this->schemaPath(), sprintf(<<<'SQL'
            CREATE TABLE "migrations" ("id" integer primary key autoincrement not null, "migration" varchar not null, "batch" integer not null);
            CREATE TABLE "probe" ("id" integer primary key autoincrement not null);
            INSERT INTO "migrations" ("migration", "batch") VALUES ('%s', 1);
            SQL, self::MIGRATION));
    }

    /**
     * Remove the probe migration.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->migrationPath !== null) {
            (new Filesystem)->deleteDirectory($this->migrationPath);
        }

        parent::tearDown();
    }

    /**
     * Test that metadata written before a migration is not served after it.
     *
     * @return void
     */
    public function testMigrateRetiresMetadataWrittenBeforeIt(): void
    {
        $this->writeAsEarlierProcess();

        $this->migrate();

        self::assertNull($this->freshProcess()->readMetadata(self::KEY));
    }

    /**
     * Test that a rollback retires metadata too.
     *
     * @return void
     */
    public function testRollbackRetiresMetadataWrittenBeforeIt(): void
    {
        $this->migrate();
        $this->writeAsEarlierProcess();

        $this->migrate('migrate:rollback');

        self::assertNull($this->freshProcess()->readMetadata(self::KEY));
    }

    /**
     * Test that a deploy with nothing to migrate keeps the metadata warm.
     *
     * @return void
     */
    public function testMigrateWithNothingPendingKeepsMetadataWarm(): void
    {
        $this->migrate();
        $this->writeAsEarlierProcess();

        $this->migrate();

        self::assertSame('warm', $this->freshProcess()->readMetadata(self::KEY));
    }

    /**
     * Test that loading a schema dump retires metadata although it leaves
     * nothing to migrate.
     *
     * @return void
     */
    public function testMigrateLoadingASchemaDumpRetiresMetadataWrittenBeforeIt(): void
    {
        $this->writeAsEarlierProcess();

        $events = $this->recordSchemaEvents();

        $this->migrate('migrate', ['--schema-path' => $this->schemaPath()]);

        self::assertSame([SchemaLoaded::class], $events->getArrayCopy());
        self::assertNull($this->freshProcess()->readMetadata(self::KEY));
    }

    /**
     * Test that rebuilding the database from a schema dump retires metadata.
     *
     * @return void
     */
    public function testFreshMigrateFromASchemaDumpRetiresMetadataWrittenBeforeIt(): void
    {
        $this->migrate();
        $this->writeAsEarlierProcess();

        $events = $this->recordSchemaEvents();

        $this->migrate('migrate:fresh', ['--schema-path' => $this->schemaPath()]);

        self::assertSame([SchemaLoaded::class], $events->getArrayCopy());
        self::assertNull($this->freshProcess()->readMetadata(self::KEY));
    }

    /**
     * Test that a store failing with an error still lets migrate succeed, with
     * a warning naming the remedy.
     *
     * @return void
     */
    public function testMigrateSucceedsWithAWarningWhenTheStoreFailsWithAnError(): void
    {
        $this->useUnreachableCacheStore(new \Error('Class "Redis" not found'));

        $warning = 'Toolkit metadata could not be invalidated after migrations: Class "Redis" not found'
            . ' Run php artisan api-toolkit:invalidate-metadata once the cache store is available.';

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === $warning && $context['exception'] instanceof \Error);

        $this->migrate();

        self::assertTrue(Schema::connection(self::CONNECTION)->hasTable('probe'));
    }

    /**
     * Test that a pretended migration keeps the metadata warm.
     *
     * @return void
     */
    public function testPretendedMigrateKeepsMetadataWarm(): void
    {
        $this->writeAsEarlierProcess();

        $this->migrate('migrate', ['--pretend' => true]);

        self::assertSame('warm', $this->freshProcess()->readMetadata(self::KEY));
    }

    /**
     * Test that with the migrations gate off, migrate leaves metadata warm.
     *
     * @return void
     */
    #[DefineEnvironment('disableMigrationInvalidation')]
    public function testMigrateKeepsMetadataWarmWhenTheGateIsOff(): void
    {
        $this->writeAsEarlierProcess();

        $this->migrate();

        self::assertSame('warm', $this->freshProcess()->readMetadata(self::KEY));
    }

    /**
     * Test that migrating another connection advances the default's generation.
     *
     * The migrator makes the migrated connection the default while it runs, so
     * a database store left to follow the default would bind to it.
     *
     * @return void
     */
    #[DefineEnvironment('useDefaultConnectionDatabaseCacheStore')]
    public function testMigrateOnAnotherConnectionAdvancesTheDefaultConnectionsGeneration(): void
    {
        $this->createCacheTable('testing');
        $this->createCacheTable(self::CONNECTION);

        $before = $this->storedGeneration('testing');

        $this->migrate();

        $after = $this->storedGeneration('testing');

        self::assertIsString($after);
        self::assertNotSame($before, $after);
        self::assertNull($this->storedGeneration(self::CONNECTION));
    }

    /**
     * Point the default cache store at a database store naming no connection.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function useDefaultConnectionDatabaseCacheStore(mixed $app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $config->set('cache.stores.database', ['driver' => 'database', 'table' => 'cache', 'connection' => null, 'lock_connection' => null]);
        $config->set('cache.default', 'database');
    }

    /**
     * Switch the migrations lifecycle gate off before the application boots.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function disableMigrationInvalidation(mixed $app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $config->set('api-toolkit.lifecycle.migrations', false);
    }

    /**
     * Create the cache table on the given connection unless it already exists.
     *
     * @param  string  $connection
     * @return void
     */
    private function createCacheTable(string $connection): void
    {
        if (Schema::connection($connection)->hasTable('cache')) {
            return;
        }

        Schema::connection($connection)->create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    }

    /**
     * Read the generation held in the given connection's cache table.
     *
     * @param  string  $connection
     * @return mixed
     */
    private function storedGeneration(string $connection): mixed
    {
        $store = Cache::build(['driver' => 'database', 'table' => 'cache', 'connection' => $connection]);

        return $store->get(CacheKeys::METADATA_GENERATION->resolveKey());
    }

    /**
     * Return the path of the schema dump recording the probe migration.
     *
     * @return string
     */
    private function schemaPath(): string
    {
        return $this->migrationPath . '/probe-schema.sql';
    }

    /**
     * Record each schema load and migration run end from here on.
     *
     * @return \ArrayObject<int, class-string>
     */
    private function recordSchemaEvents(): \ArrayObject
    {
        /** @var \ArrayObject<int, class-string> $events */
        $events = new \ArrayObject;

        Event::listen([SchemaLoaded::class, MigrationsEnded::class], static function (object $event) use ($events): void {
            $events[] = $event::class;
        });

        return $events;
    }

    /**
     * Write the probe metadata as a process that has since gone away.
     *
     * @return void
     */
    private function writeAsEarlierProcess(): void
    {
        $this->freshProcess()->rememberMetadataForever(self::KEY, fn (): string => 'warm');
    }

    /**
     * Build a writer as a separate process would hold it.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    private function freshProcess(): MetadataCacheWriter
    {
        return new MetadataCacheWriter(new MetadataGeneration);
    }

    /**
     * Run a migration command against the probe connection and path.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $options
     * @return void
     */
    private function migrate(string $command = 'migrate', array $options = []): void
    {
        $this->artisan($command, [
            '--database' => self::CONNECTION,
            '--path'     => $this->migrationPath,
            '--realpath' => true,
            ...$options,
        ])->assertExitCode(0)->run(); // @phpstan-ignore method.nonObject
    }
}
