<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
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

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        FunctionOverrides::reset();

        parent::tearDown();
    }

    /**
     * Get the package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders(\Illuminate\Foundation\Application $app): array
    {
        return [
            ApiServiceProvider::class,
        ];
    }

    /**
     * Define the environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment(\Illuminate\Foundation\Application $app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('api-toolkit.parser.alias', 'api.query');
        $app['config']->set('api-toolkit.parser.register_middleware', false);
        $app['config']->set('api-toolkit.resources.fixed_fields', ['id', '_type']);
        $app['config']->set('api-toolkit.resources.enable_dynamic_morph_mapping', false);
        $app['config']->set('api-toolkit.notifications.enable_logging', false);
        $app['config']->set('api-toolkit.logging.cloudwatch.enabled', false);
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
        $this->createLogsTable();
    }

    /**
     * Create the users table.
     *
     * @return void
     */
    private function createUsersTable(): void
    {
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
        Schema::create('post_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['post_id', 'tag_id']);
        });
    }

    /**
     * Create the logs table.
     *
     * @return void
     */
    private function createLogsTable(): void
    {
        Schema::create('logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('level');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at', 6)->nullable();
        });
    }
}
