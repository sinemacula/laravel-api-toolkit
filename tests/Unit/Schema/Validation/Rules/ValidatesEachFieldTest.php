<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatesEachField;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Tests for the ValidatesEachField base validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidatesEachField::class)]
final class ValidatesEachFieldTest extends TestCase
{
    /**
     * Test that checkField runs for every non-null field and the errors from
     * each are accumulated in field order.
     *
     * @return void
     */
    public function testRunsCheckFieldForEachNonNullFieldAndAccumulates(): void
    {
        $schema = new CompiledSchema(
            fields: ['first' => $this->field(), 'second' => $this->field()],
            counts: [],
        );

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('first', $errors[0]->fieldKey);
        self::assertSame('second', $errors[1]->fieldKey);
    }

    /**
     * Test that a null field definition is skipped without invoking checkField.
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function testNullFieldIsSkipped(): void
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, ['ghost' => null]);
        $reflection->getProperty('counts')->setValue($schema, []);

        self::assertSame([], $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema));
    }

    /**
     * Test that a null field does not halt iteration, so a later field's error
     * is still reported.
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function testContinuesPastNullField(): void
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, ['ghost' => null, 'name' => $this->field()]);
        $reflection->getProperty('counts')->setValue($schema, []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
    }

    /**
     * Build a compiled field definition with no meaningful configuration.
     *
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function field(): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
        );
    }

    /**
     * Build a concrete rule that emits one error per field, keyed by the field.
     *
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatesEachField
     */
    private function makeRule(): ValidatesEachField
    {
        return new class extends ValidatesEachField {
            /**
             * Return a single error identifying the field.
             *
             * @param  string  $resourceClass
             * @param  string  $key
             * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
             * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
             */
            #[\Override]
            protected function checkField(string $resourceClass, string $key, CompiledFieldDefinition $field): array
            {
                return [new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: 'stub',
                )];
            }
        };
    }
}
