<?php

namespace Tests\Unit\Repositories\Traits;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Repositories\Traits\ResolvesResource;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ResolvesResource trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResolvesResource::class)]
class ResolvesResourceTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /**
     * Test that usingResource sets the custom resource class.
     *
     * @return void
     */
    public function testUsingResourceSetsCustomResourceClass(): void
    {
        $consumer = $this->createConsumer();

        $result = $consumer->usingResource(UserResource::class);

        static::assertSame($consumer, $result);

        $customResource = $this->getProperty($consumer, 'customResourceClass');

        static::assertSame(UserResource::class, $customResource);
    }

    /**
     * Test that resolveResource returns the custom class when set.
     *
     * @return void
     */
    public function testResolveResourceReturnsCustomClassWhenSet(): void
    {
        $consumer = $this->createConsumer();
        $consumer->usingResource(UserResource::class);

        $result = $this->invokeMethod($consumer, 'resolveResource', new User);

        static::assertSame(UserResource::class, $result);
    }

    /**
     * Test that resolveResource returns the mapped resource from config.
     *
     * @return void
     */
    public function testResolveResourceReturnsMappedResourceFromConfig(): void
    {
        Config::set('api-toolkit.resources.resource_map.' . User::class, UserResource::class);

        $consumer = $this->createConsumer();

        $result = $this->invokeMethod($consumer, 'resolveResource', new User);

        static::assertSame(UserResource::class, $result);
    }

    /**
     * Test that resolveResource returns null when no mapping exists.
     *
     * @return void
     */
    public function testResolveResourceReturnsNullWhenNoMappingExists(): void
    {
        Config::set('api-toolkit.resources.resource_map', []);

        $consumer = $this->createConsumer();

        $result = $this->invokeMethod($consumer, 'resolveResource', new User);

        static::assertNull($result);
    }

    /**
     * Create a test consumer class that uses the ResolvesResource trait.
     *
     * @return object
     */
    private function createConsumer(): object
    {
        return new class {
            use ResolvesResource;
        };
    }
}
