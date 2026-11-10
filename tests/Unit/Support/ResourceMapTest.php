<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Support\ResourceMap;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for reading the configured resource map.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResourceMap::class)]
final class ResourceMapTest extends TestCase
{
    /**
     * Test that every binding of an existing model to an existing resource is
     * returned as configured.
     *
     * @return void
     */
    public function testConfiguredReturnsTheBindings(): void
    {
        Config::set('api-toolkit.resources.resource_map', [User::class => UserResource::class, Post::class => PostResource::class]);

        self::assertSame([User::class => UserResource::class, Post::class => PostResource::class], ResourceMap::configured());
    }

    /**
     * Test that a map that is not configured as an array reads as empty.
     *
     * @return void
     */
    public function testConfiguredReadsAnUnconfiguredMapAsEmpty(): void
    {
        Config::set('api-toolkit.resources.resource_map', null);

        self::assertSame([], ResourceMap::configured());
    }

    /**
     * Provide bindings that do not bind an existing model class to an existing
     * resource class, with the binding each is reported as.
     *
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function invalidBindingProvider(): iterable
    {
        yield 'list entry' => [[UserResource::class], '0 => ' . UserResource::class];
        yield 'missing model' => [['Tests\Fixtures\Models\Missing' => UserResource::class], 'Tests\Fixtures\Models\Missing => ' . UserResource::class];
        yield 'missing resource' => [[User::class => 'Tests\Fixtures\Resources\Missing'], User::class . ' => Tests\Fixtures\Resources\Missing'];
        yield 'non-string resource' => [[User::class => 1], User::class . ' => int'];
    }

    /**
     * Test that a binding naming no class on either side fails loud, naming the
     * binding.
     *
     * @param  array<array-key, mixed>  $map
     * @param  string  $binding
     * @return void
     */
    #[DataProvider('invalidBindingProvider')]
    public function testConfiguredRefusesABindingNamingNoClass(array $map, string $binding): void
    {
        Config::set('api-toolkit.resources.resource_map', $map);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(sprintf('The resource map must bind an existing model class to an existing resource class, [%s] given', $binding));

        ResourceMap::configured();
    }
}
