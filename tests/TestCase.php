<?php

declare(strict_types = 1);

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use SineMacula\ApiToolkit\ApiServiceProvider;
use SineMacula\ApiToolkit\Repositories\RepositoryResolver;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\DummyRepository;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\FunctionOverrides;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        FunctionOverrides::reset();
        RepositoryResolver::flush();

        $this->configurePackageMappings();
        $this->resetSchema();
    }

    protected function tearDown(): void
    {
        RepositoryResolver::flush();

        parent::tearDown();
    }

    /**
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ApiServiceProvider::class,
            ExporterServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.debug', false);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('cache.default', 'array');

        $app['config']->set('logging.channels.database.level', 'debug');
        $app['config']->set('logging.channels.database.days', 30);
        $app['config']->set('logging.channels.fallback.channels', ['single']);
    }

    private function configurePackageMappings(): void
    {
        Config::set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
        ]);

        Config::set('api-toolkit.repositories.repository_map', [
            'users' => UserRepository::class,
            'dummy' => DummyRepository::class,
        ]);

        Config::set('api-toolkit.resources.fixed_fields', ['id', '_type']);
    }

    private function resetSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (['taggables', 'tag_user', 'posts', 'users', 'organizations', 'profiles', 'tags', 'logs'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('owner_type')->nullable();
            $table->integer('age')->nullable();
            $table->boolean('active')->default(false);
            $table->text('meta')->nullable();
            $table->text('settings')->nullable();
            $table->string('state')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->boolean('published')->default(false);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('tag_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tag_id');
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('taggable_id');
            $table->string('taggable_type');
        });

        Schema::create('logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('level');
            $table->longText('message');
            $table->text('context')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
