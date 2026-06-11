<?php

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateComputedFields;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the ValidateComputedFields validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateComputedFields::class)]
class ValidateComputedFieldsTest extends TestCase
{
    /**
     * Test that no errors are returned for a schema with a callable compute
     * value.
     *
     * @return void
     */
    public function testNoErrorsForSchemaWithCallableCompute(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'full_name' => new CompiledFieldDefinition(
                    accessor: null,
                    compute: fn ($resource) => $resource->first_name . ' ' . $resource->last_name,
                    relation: null,
                    resource: null,
                    fields: null,
                    constraint: null,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that no errors are returned for a schema with a string compute
     * value matching a method on the resource class.
     *
     * @return void
     */
    public function testNoErrorsForSchemaWithValidMethodNameCompute(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'label' => new CompiledFieldDefinition(
                    accessor: null,
                    compute: 'schema',
                    relation: null,
                    resource: null,
                    fields: null,
                    constraint: null,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that an error is reported for a non-callable, non-method compute
     * value.
     *
     * @return void
     */
    public function testReportsNonCallableNonMethodCompute(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'label' => new CompiledFieldDefinition(
                    accessor: null,
                    compute: 'nonExistentMethod',
                    relation: null,
                    resource: null,
                    fields: null,
                    constraint: null,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('label', $errors[0]->fieldKey);
        static::assertSame(
            'Computed field value is not callable and does not reference an existing method on the resource class',
            $errors[0]->defect,
        );
    }

    /**
     * Test that no errors are returned for a global function name compute
     * value.
     *
     * @return void
     */
    public function testNoErrorsForGlobalFunctionCompute(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'length' => $this->makeField('strlen'),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that validation continues past fields without compute values and
     * still reports later defects.
     *
     * @return void
     */
    public function testContinuesPastFieldsWithoutCompute(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'clean' => $this->makeField(null),
                'bad'   => $this->makeField('nonExistentMethod'),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that validation continues past fields with Closure compute values
     * and still reports later defects.
     *
     * @return void
     */
    public function testContinuesPastClosureComputeFields(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'clean' => $this->makeField(static fn ($resource) => $resource->name),
                'bad'   => $this->makeField('nonExistentMethod'),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that validation continues past fields with valid method name
     * compute values and still reports later defects.
     *
     * @return void
     */
    public function testContinuesPastMethodNameComputeFields(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'clean' => $this->makeField('schema'),
                'bad'   => $this->makeField('nonExistentMethod'),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that every invalid compute value is reported.
     *
     * @return void
     */
    public function testReportsAllInvalidComputes(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'first'  => $this->makeField('nonExistentMethod'),
                'second' => $this->makeField('anotherMissingMethod'),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertCount(2, $errors);
        static::assertSame('first', $errors[0]->fieldKey);
        static::assertSame('second', $errors[1]->fieldKey);
    }

    /**
     * Test that fields without compute values produce no errors.
     *
     * @return void
     */
    public function testSkipsFieldsWithNullCompute(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'name' => new CompiledFieldDefinition(
                    accessor: 'name',
                    compute: null,
                    relation: null,
                    resource: null,
                    fields: null,
                    constraint: null,
                    extras: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateComputedFields;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Create a compiled field definition with the given compute value.
     *
     * @param  mixed  $compute
     * @return \SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition
     */
    private function makeField(mixed $compute): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: null,
            compute: $compute,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [],
            transformers: [],
        );
    }
}
