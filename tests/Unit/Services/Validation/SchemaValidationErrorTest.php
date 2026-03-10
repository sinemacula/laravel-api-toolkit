<?php

namespace Tests\Unit\Services\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Services\Validation\SchemaValidationError;

/**
 * Tests for the SchemaValidationError value object.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(SchemaValidationError::class)]
class SchemaValidationErrorTest extends TestCase
{
    /**
     * Test that the constructor sets properties.
     *
     * @return void
     */
    public function testConstructorSetsProperties(): void
    {
        $error = new SchemaValidationError(
            resourceClass: 'App\Http\Resources\UserResource',
            fieldKey: 'organization',
            defect: 'Guard at index 0 is not callable',
        );

        static::assertSame('App\Http\Resources\UserResource', $error->resourceClass);
        static::assertSame('organization', $error->fieldKey);
        static::assertSame('Guard at index 0 is not callable', $error->defect);
    }

    /**
     * Test that __toString formats all three elements.
     *
     * @return void
     */
    public function testToStringFormatsAllThreeElements(): void
    {
        $error = new SchemaValidationError(
            resourceClass: 'App\Http\Resources\UserResource',
            fieldKey: 'full_label',
            defect: 'Missing accessor',
        );

        static::assertSame(
            '[App\Http\Resources\UserResource] Field "full_label": Missing accessor',
            (string) $error,
        );
    }

    /**
     * Test that properties are readonly.
     *
     * @return void
     */
    public function testPropertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(SchemaValidationError::class);

        static::assertTrue($reflection->isReadOnly());
    }
}
