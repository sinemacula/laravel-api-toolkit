<?php

namespace Tests\Unit\Events;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use Tests\TestCase;

/**
 * Tests for the CacheFlushed event.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheFlushed::class)]
class CacheFlushedTest extends TestCase
{
    /**
     * Test that the event can be instantiated with no arguments.
     *
     * @return void
     */
    public function testCacheFlushedCanBeInstantiated(): void
    {
        $event = new CacheFlushed;

        static::assertInstanceOf(CacheFlushed::class, $event);
    }

    /**
     * Test that the event is a pure marker with no additional public
     * properties or methods beyond those inherited from object.
     *
     * @return void
     */
    public function testCacheFlushedIsMarkerEvent(): void
    {
        $reflection = new \ReflectionClass(CacheFlushed::class);

        $publicProperties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        static::assertEmpty($publicProperties, 'Marker event should have no public properties');

        $objectMethods = get_class_methods(new \stdClass) ?: [];
        $eventMethods  = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $additionalMethods = array_diff($eventMethods, $objectMethods);

        static::assertEmpty($additionalMethods, 'Marker event should not declare additional public methods');
    }
}
