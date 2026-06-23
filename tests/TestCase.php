<?php

declare(strict_types = 1);

namespace Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SineMacula\ApiToolkit\ApiServiceProvider;
use Tests\Fixtures\Support\FunctionOverrides;

/**
 * Base test case for the API toolkit package.
 *
 * Provides an in-memory SQLite database, configures the package service
 * providers, and sets up the schema required by fixture models.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    /** @var string|null The per-test isolated file-cache directory, if one was provisioned. */
    private ?string $fileCachePath = null;

    /**
     * Clean up the testing environment before the next test.
     *
     * Resets the morph map enforcement that registerMorphMap() applies as
     * process-global static state, so tests remain order-independent, and
     * removes the per-test file-cache directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        FunctionOverrides::reset();

        Relation::morphMap([], false);
        Relation::requireMorphMap(false);

        if ($this->fileCachePath !== null) {
            (new Filesystem)->deleteDirectory($this->fileCachePath);
        }

        parent::tearDown();
    }

    /**
     * Get the package providers.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
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
    protected function defineEnvironment(mixed $app): void
    {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        // Isolate the file cache per test so parallel mutation runs (each
        // mutant is a separate process) never collide on a shared cache
        // directory, which otherwise makes TTL/expiry-sensitive cache
        // assertions flap and the mutation score non-deterministic.
        $this->fileCachePath = sys_get_temp_dir() . '/api-toolkit-test-cache-' . getmypid() . '-' . uniqid('', true);
        $config->set('cache.stores.file.path', $this->fileCachePath);

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', $this->getDatabaseConnection());

        $config->set('api-toolkit.parser.alias', 'api.query');
        $config->set('api-toolkit.parser.register_middleware', false);
        $config->set('api-toolkit.resources.fixed_fields', ['id', '_type']);
        $config->set('api-toolkit.resources.enable_dynamic_morph_mapping', false);
        $config->set('api-toolkit.notifications.enable_logging', false);
        $config->set('api-toolkit.logging.cloudwatch.enabled', false);
    }

    /**
     * Define the database migrations.
     *
     * @return void
     */
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
    }

    /**
     * Get the database connection configuration.
     *
     * Reads the DB_DRIVER environment variable to determine which database to
     * use. Defaults to in-memory SQLite for fast local testing.
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
        });
    }

    /**
     * Create the articles table.
     *
     * Wide, soft-deleting table backing the Article fixture used by the
     * column-narrowing integration suite.
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
        });
    }
}
