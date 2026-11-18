<?php

declare(strict_types = 1);

namespace Tests\Unit\Config;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Config\ResourceSettings;
use SineMacula\ApiToolkit\Http\Middleware\ThrottleRequests;
use Tests\Concerns\ReadsSettings;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the typed resource settings.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceSettings::class)]
final class ResourceSettingsTest extends TestCase
{
    use ReadsSettings;

    /**
     * Test that null paths read as the default roots.
     *
     * @return void
     */
    public function testNullPathsReadAsTheDefaultRoots(): void
    {
        Config::set('api-toolkit.resources.paths', null);

        self::assertNull(ResourceSettings::paths());
    }

    /**
     * Test that a resource map with a non-string key fails.
     *
     * @return void
     */
    public function testResourceMapRefusesANonStringKey(): void
    {
        Config::set('api-toolkit.resources.resource_map', [UserResource::class]);

        $this->assertRefused(
            ResourceSettings::resourceMap(...),
            sprintf('The api-toolkit.resources.resource_map setting must be a map of model classes to resource classes; [\'%s\'] given.', UserResource::class),
        );
    }

    /**
     * Test that the resource bindings accept a model bound to an API resource
     * and refuse a resource that is not one.
     *
     * @return void
     */
    public function testResourceBindingsRequireAModelAndAnApiResource(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class, Post::class => PostResource::class]);

        self::assertSame([User::class => UserResource::class, Post::class => PostResource::class], ResourceSettings::bindings());

        Config::set('api-toolkit.resources.resource_map', [User::class => ThrottleRequests::class]);

        $this->assertRefused(
            ResourceSettings::bindings(...),
            sprintf(
                'The api-toolkit.resources.resource_map setting must be a map of Eloquent model classes to API resource classes; [\'%s => %s\'] given.',
                User::class,
                ThrottleRequests::class,
            ),
        );
    }

    /**
     * Return the settings class under test.
     *
     * @return class-string
     */
    #[\Override]
    protected static function settings(): string
    {
        return ResourceSettings::class;
    }
}
