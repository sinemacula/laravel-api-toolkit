<?php

declare(strict_types = 1);

namespace Tests;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\Concerns\FieldResolver;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Search\IndexProof;
use SineMacula\ApiToolkit\Search\SearchPlan;
use Tests\Fixtures\Support\FunctionOverrides;
use Tests\Fixtures\Support\UnreachableCacheStore;

/**
 * Base test case for the API toolkit package.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /** @var string|null The per-test isolated file-cache directory, if one was provisioned. */
    private ?string $fileCachePath = null;

    /** @var string|null The per-test isolated bootstrap directory, if one was provisioned. */
    private ?string $bootstrapPath = null;

    /** @var array<int, string> Toolkit cache keys whose value does not read back without its classes */
    private array $unreadableCacheWrites = [];

    /**
     * Fail the test when a toolkit cache write would not read back with every
     * class refused, as Laravel's serializable_classes default does.
     *
     * Writes recorded by an event fake are checked here, since the fake keeps
     * them from the listener.
     *
     * @return void
     */
    #[\Override]
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $events = Event::getFacadeRoot();

        if ($events instanceof EventFake) {
            $events->dispatched(KeyWritten::class)->each(function (mixed $arguments): void {
                if (!is_array($arguments) || !$arguments[0] instanceof KeyWritten) {
                    return;
                }

                $this->recordUnreadableCacheWrite($arguments[0]);
            });
        }

        if ($this->unreadableCacheWrites === []) {
            return;
        }

        static::fail('These cache entries do not read back with serializable classes disabled: ' . implode(', ', array_unique($this->unreadableCacheWrites)));
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        FunctionOverrides::reset();

        // Clear the process-static caches a real request boundary would flush
        FieldResolver::clearCache();
        ValueResolver::clearCache();
        EagerLoadPlanner::clearCache();
        SchemaCompiler::clearCache();
        SearchPlan::clearCache();
        IndexProof::clearCache();
        MetadataCacheWriter::reset();

        Relation::morphMap([], false);
        Relation::requireMorphMap(false);

        if ($this->fileCachePath !== null) {
            (new Filesystem)->deleteDirectory($this->fileCachePath);
        }

        if ($this->bootstrapPath !== null) {
            (new Filesystem)->deleteDirectory($this->bootstrapPath);
            $this->bootstrapPath = null;
        }

        parent::tearDown();
    }

    /**
     * Return the key the store holds the given metadata key under.
     *
     * @param  string  $key
     * @return string
     */
    protected function metadataStorageKey(string $key): string
    {
        /** @var \SineMacula\ApiToolkit\Cache\MetadataCacheWriter $writer */
        $writer = $this->app->make(MetadataCacheWriter::class); // @phpstan-ignore method.nonObject

        return $writer->storageKey($key);
    }

    /**
     * Point the application at a bootstrap directory of its own, so parallel
     * processes never share a compiled file.
     *
     * @return string
     */
    protected function useIsolatedBootstrapPath(): string
    {
        assert($this->app !== null);

        $this->bootstrapPath = sys_get_temp_dir() . '/api-toolkit-bootstrap-' . getmypid() . '-' . uniqid('', true);

        mkdir($this->bootstrapPath . '/cache', 0o755, true);

        $this->app->useBootstrapPath($this->bootstrapPath);

        return $this->bootstrapPath;
    }

    /**
     * Write the given PHP source as the compiled resource map.
     *
     * @param  string  $php
     * @return string
     */
    protected function writeCompiledResources(string $php): string
    {
        assert($this->app !== null);

        $path = $this->app->bootstrapPath('cache/api-toolkit-resources.php');

        file_put_contents($path, $php);

        return $path;
    }

    /**
     * Write the given map as the compiled resource map.
     *
     * @param  array<class-string, class-string>  $map
     * @return string
     */
    protected function writeCompiledMap(array $map): string
    {
        return $this->writeCompiledResources('<?php return ' . var_export($map, true) . ';');
    }

    /**
     * Make the default cache store one whose every operation throws.
     *
     * @return void
     */
    protected function useUnreachableCacheStore(): void
    {
        assert($this->app !== null);

        UnreachableCacheStore::install($this->app);
    }

    /**
     * Run the given Artisan command as a pending command.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     * @return \Illuminate\Testing\PendingCommand
     */
    protected function pendingArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        assert($pending instanceof PendingCommand);

        return $pending;
    }

    /**
     * Point the default cache store at one that keeps nothing and rejects every
     * write.
     *
     * @return void
     */
    protected function useRejectingCacheStore(): void
    {
        Config::set('cache.stores.rejecting', ['driver' => 'null']);
        Config::set('cache.default', 'rejecting');
    }

    /**
     * Get the package providers.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ApiServiceProvider::class,
        ];
    }

    /**
     * Define the environment setup.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        // Isolate the file cache so parallel mutant processes never share it
        $this->fileCachePath = sys_get_temp_dir() . '/api-toolkit-test-cache-' . getmypid() . '-' . uniqid('', true);
        $config->set('cache.stores.file.path', $this->fileCachePath);

        // Refuse cached classes on a serializing store, as production does.
        $config->set('cache.serializable_classes', false);
        $config->set('cache.stores.array.serialize', true);

        /** @var \Illuminate\Contracts\Events\Dispatcher $events */
        $events = $app['events'];

        $events->listen(KeyWritten::class, $this->recordUnreadableCacheWrite(...));

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', $this->getDatabaseConnection());

        $config->set('api-toolkit.parser.alias', 'api.query');
        $config->set('api-toolkit.parser.register_middleware', false);
        $config->set('api-toolkit.resources.fixed_fields', ['id', '_type']);
        $config->set('api-toolkit.resources.enable_dynamic_morph_mapping', false);
        $config->set('api-toolkit.notifications.enable_logging', false);
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        $this->createUsersTable();
        $this->createOrganizationsTable();
        $this->createPostsTable();
        $this->createProfilesTable();
        $this->createTagsTable();
        $this->createPostTagTable();
        $this->createCountriesTable();
        $this->createCountryPostTable();
        $this->createLogsTable();
        $this->createArticlesTable();
        $this->createCommentsTable();
        $this->createDevicesTable();
        $this->createImportRowsTable();
        $this->createSearchIndexes();
    }

    /**
     * Record a toolkit cache write whose value does not read back unchanged
     * with every class refused.
     *
     * Recorded before serializing, so a value that cannot be serialized at all
     * stays recorded.
     *
     * @param  \Illuminate\Cache\Events\KeyWritten  $event
     * @return void
     */
    private function recordUnreadableCacheWrite(KeyWritten $event): void
    {
        $prefix = Config::get('api-toolkit.cache.prefix', 'api-toolkit');

        if (!is_string($prefix) || !str_starts_with($event->key, $prefix . ':')) {
            return;
        }

        $this->unreadableCacheWrites[] = $event->key;

        if (unserialize(serialize($event->value), ['allowed_classes' => false]) !== $event->value) {
            return;
        }

        array_pop($this->unreadableCacheWrites);
    }

    /**
     * Get the database connection configuration from the DB_DRIVER environment
     * variable, defaulting to in-memory SQLite.
     *
     * @return array<string, mixed>
     */
    private function getDatabaseConnection(): array
    {
        $driver = env('DB_DRIVER', 'sqlite');

        return match ($driver) {
            'mysql' => [
                'driver'   => 'mysql',
                'host'     => env('DB_HOST', '127.0.0.1'),
                'port'     => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'api_toolkit_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'prefix'   => '',
                'charset'  => 'utf8mb4',
            ],
            'pgsql' => [
                'driver'   => 'pgsql',
                'host'     => env('DB_HOST', '127.0.0.1'),
                'port'     => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'api_toolkit_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'prefix'   => '',
                'charset'  => 'utf8',
            ],
            default => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        };
    }

    /**
     * Create the users table.
     *
     * @return void
     */
    private function createUsersTable(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('name');
            $table->index('organization_id');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index(['status', 'name']);
        });
    }

    /**
     * Create the organizations table.
     *
     * @return void
     */
    private function createOrganizationsTable(): void
    {
        Schema::dropIfExists('organizations');
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    /**
     * Create the posts table.
     *
     * @return void
     */
    private function createPostsTable(): void
    {
        Schema::dropIfExists('posts');
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'published']);
        });
    }

    /**
     * Create the profiles table.
     *
     * @return void
     */
    private function createProfilesTable(): void
    {
        Schema::dropIfExists('profiles');
        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('bio')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Create the tags table.
     *
     * @return void
     */
    private function createTagsTable(): void
    {
        Schema::dropIfExists('tags');
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    /**
     * Create the post_tag pivot table.
     *
     * @return void
     */
    private function createPostTagTable(): void
    {
        Schema::dropIfExists('post_tag');
        Schema::create('post_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['post_id', 'tag_id']);
        });
    }

    /**
     * Create the countries table with a non-incrementing string primary key.
     *
     * @return void
     */
    private function createCountriesTable(): void
    {
        Schema::dropIfExists('countries');
        Schema::create('countries', function (Blueprint $table): void {
            $table->string('code')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Create the country_post pivot table keyed by the country code.
     *
     * @return void
     */
    private function createCountryPostTable(): void
    {
        Schema::dropIfExists('country_post');
        Schema::create('country_post', function (Blueprint $table): void {
            $table->unsignedBigInteger('post_id');
            $table->string('country_code');
            $table->primary(['post_id', 'country_code']);
        });
    }

    /**
     * Create the logs table.
     *
     * @return void
     */
    private function createLogsTable(): void
    {
        Schema::dropIfExists('logs');
        Schema::create('logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('level');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at', 6)->nullable();

            $table->index(['level', 'created_at']);
        });
    }

    /**
     * Create the soft-deleting articles table.
     *
     * @return void
     */
    private function createArticlesTable(): void
    {
        Schema::dropIfExists('articles');
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('body');
            $table->text('summary');
            $table->string('status')->default('draft');
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('views');
            $table->index(['status', 'views']);
        });
    }

    /**
     * Create the soft-deleting comments table.
     *
     * @return void
     */
    private function createCommentsTable(): void
    {
        Schema::dropIfExists('comments');
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });
    }

    /**
     * Create the devices table, holding a pure enum platform and a backed enum
     * status.
     *
     * @return void
     */
    private function createDevicesTable(): void
    {
        Schema::dropIfExists('devices');
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('platform')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Create the import rows table, with no primary key and no index.
     *
     * @return void
     */
    private function createImportRowsTable(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::create('import_rows', function (Blueprint $table): void {
            $table->string('reference');
            $table->string('payload');
        });
    }

    /**
     * Create the indexes the shipped search drivers prove declarations against.
     *
     * MySQL takes one fulltext index over both columns, PostgreSQL one trigram
     * index each, and SQLite none.
     *
     * @return void
     */
    private function createSearchIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {

            DB::statement('alter table `users` add fulltext index `users_search_ngram` (`name`, `email`) with parser ngram');

            return;
        }

        if ($driver !== 'pgsql') {
            return;
        }

        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create index users_name_trgm on users using gin (name gin_trgm_ops)');
        DB::statement('create index users_email_trgm on users using gin (email gin_trgm_ops)');
    }
}
