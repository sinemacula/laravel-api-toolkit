<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatesCallableLists;

/**
 * Tests for the ValidatesCallableLists base validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidatesCallableLists::class)]
final class ValidatesCallableListsTest extends TestCase
{
    /**
     * Test that a field whose callable list is fully callable produces no
     * errors.
     *
     * @return void
     */
    public function testNoErrorsWhenEveryEntryIsCallable(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [fn ($value, $model) => true],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['name' => $field], counts: []);

        self::assertSame([], $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema));
    }

    /**
     * Test that a non-callable entry is reported using the concrete label.
     *
     * @return void
     */
    public function testReportsNonCallableEntry(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['name' => $field], counts: []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('Item at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test that a null field definition present under a declared key is skipped
     * without error.
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function testSkipsNullFieldDefinition(): void
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, ['ghost' => null]);
        $reflection->getProperty('counts')->setValue($schema, []);

        self::assertSame([], $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema));
    }

    /**
     * Test that a null field definition does not halt iteration, so a later
     * field's non-callable entry is still reported.
     *
     * @return void
     *
     * @throws \ReflectionException
     */
    public function testContinuesPastNullFieldDefinition(): void
    {
        $bad = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, ['ghost' => null, 'name' => $bad]);
        $reflection->getProperty('counts')->setValue($schema, []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
    }

    /**
     * Test that errors from every field are accumulated rather than only the
     * final field's errors surviving.
     *
     * @return void
     */
    public function testAccumulatesErrorsAcrossFields(): void
    {
        $first = new CompiledFieldDefinition(
            accessor: 'first',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $second = new CompiledFieldDefinition(
            accessor: 'second',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['also_not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['first' => $first, 'second' => $second], counts: []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('first', $errors[0]->fieldKey);
        self::assertSame('second', $errors[1]->fieldKey);
    }

    /**
     * Test that every non-callable entry in a single field's list is reported,
     * so the collected errors accumulate rather than only the first surviving.
     *
     * @return void
     */
    public function testReportsEveryNonCallableEntryInAList(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: ['not_a_function', 'also_not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['name' => $field], counts: []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('Item at index 0 is not callable', $errors[0]->defect);
        self::assertSame('Item at index 1 is not callable', $errors[1]->defect);
    }

    /**
     * Test that a callable entry does not halt the list scan, so a following
     * non-callable entry is still reported at its true index.
     *
     * @return void
     */
    public function testContinuesPastCallableEntryInList(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [fn ($value, $model) => true, 'not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(fields: ['name' => $field], counts: []);

        $errors = $this->makeRule()->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('Item at index 1 is not callable', $errors[0]->defect);
    }

    /**
     * Build a concrete rule that validates each field's guard list.
     *
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatesCallableLists
     */
    private function makeRule(): ValidatesCallableLists
    {
        return new class extends ValidatesCallableLists {
            /**
             * Return the callable list to validate for the given field.
             *
             * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
             * @return array<int, callable(mixed, mixed): mixed>
             */
            #[\Override]
            protected function getCallables(CompiledFieldDefinition $field): array
            {
                return $field->guards;
            }

            /**
             * Return the human-readable label used in defect messages.
             *
             * @return string
             */
            #[\Override]
            protected function getLabel(): string
            {
                return 'Item';
            }
        };
    }
}
