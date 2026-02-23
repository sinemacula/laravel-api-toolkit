<?php

namespace Tests\Unit\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\BaseResource;
use Tests\Concerns\InteractsWithNonPublicMembers;

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
    use InteractsWithNonPublicMembers;

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
        static::assertSame($fields, $this->getProperty($resource, 'fields'));
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

        static::assertNull($this->getProperty($resource, 'fields'));
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
        static::assertSame($fields, $this->getProperty($resource, 'excludedFields'));
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

        static::assertNull($this->getProperty($resource, 'excludedFields'));
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
        static::assertTrue($this->getProperty($resource, 'all'));
    }

    /**
     * Test default property values.
     *
     * @return void
     */
    public function testDefaultPropertyValues(): void
    {
        $resource = $this->createConcreteResource((object) ['id' => 1]);

        static::assertFalse($this->getProperty($resource, 'all'));
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
