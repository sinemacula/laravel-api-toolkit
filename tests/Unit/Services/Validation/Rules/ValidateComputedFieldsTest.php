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
}
