<?php

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\BaseResource;

/**
 * Tests for the BaseResource abstract class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(BaseResource::class)]
class BaseResourceTest extends TestCase
{
    /**
     * Test that the resource extends JsonResource.
     *
     * @return void
     */
    public function testExtendsJsonResource(): void
    {
        $resource = $this->createConcreteResource((object) ['id' => 1]);

        static::assertInstanceOf(JsonResource::class, $resource);
    }

    /**
     * Test that withFields sets fields and returns static.
     *
     * @return void
     */
    public function testWithFieldsSetsFieldsAndReturnsStatic(): void
    {
        $resource = $this->createConcreteResource((object) ['id' => 1]);
        $fields   = ['id', 'name', 'email'];

        $result = $resource->withFields($fields);

        static::assertSame($resource, $result);

        $reflection = new \ReflectionProperty($resource, 'fields');
        static::assertSame($fields, $reflection->getValue($resource));
    }

    /**
     * Test that withFields accepts null.
     *
     * @return void
     */
    public function testWithFieldsAcceptsNull(): void
    {
        $resource = $this->createConcreteResource((object) ['id' => 1]);

        $resource->withFields(['id'])->withFields(null);

        $reflection = new \ReflectionProperty($resource, 'fields');
        static::assertNull($reflection->getValue($resource));
    }

    /**
     * Test that withoutFields sets excluded fields and returns static.
     *
     * @return void
     */
    public function testWithoutFieldsSetsExcludedFieldsAndReturnsStatic(): void
    {
        $resource = $this->createConcreteResource((object) ['id' => 1]);
        $fields   = ['password', 'secret'];

        $result = $resource->withoutFields($fields);

        static::assertSame($resource, $result);

        $reflection = new \ReflectionProperty($resource, 'excludedFields');
        static::assertSame($fields, $reflection->getValue($resource));
    }

    /**
     * Test that withoutFields accepts null.
     *
     * @return void
     */
    public function testWithoutFieldsAcceptsNull(): void
    {
        $resource = $this->createConcreteResource((object) ['id' => 1]);

        $resource->withoutFields(['password'])->withoutFields(null);

        $reflection = new \ReflectionProperty($resource, 'excludedFields');
        static::assertNull($reflection->getValue($resource));
    }

    /**
     * Test that withAll sets the all flag and returns static.
     *
     * @return void
     */
    public function testWithAllSetsAllFlagAndReturnsStatic(): void
    {
        $resource = $this->createConcreteResource((object) ['id' => 1]);

        $result = $resource->withAll();

        static::assertSame($resource, $result);

        $reflection = new \ReflectionProperty($resource, 'all');
        static::assertTrue($reflection->getValue($resource));
    }

    /**
     * Test default property values.
     *
     * @return void
     */
    public function testDefaultPropertyValues(): void
    {
        $resource = $this->createConcreteResource((object) ['id' => 1]);

        $allReflection = new \ReflectionProperty($resource, 'all');
        static::assertFalse($allReflection->getValue($resource));
    }

    /**
     * Create a concrete instance of the abstract BaseResource.
     *
     * @param  mixed  $resource
     * @return \SineMacula\ApiToolkit\Http\Resources\BaseResource
     */
    private function createConcreteResource(mixed $resource): BaseResource
    {
        return new class ($resource) extends BaseResource {
            /**
             * Transform the resource into an array.
             *
             * @param  \Illuminate\Http\Request  $request
             * @return array<string, mixed>
             */
            public function toArray(\Illuminate\Http\Request $request): array
            {
                return [];
            }
        };
    }
}
