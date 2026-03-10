<?php

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateTransformers;

/**
 * Tests for the ValidateTransformers validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateTransformers::class)]
class ValidateTransformersTest extends TestCase
{
    /**
     * Test no errors for schema with callable transformers.
     *
     * @return void
     */
    public function testNoErrorsForSchemaWithCallableTransformers(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [],
            transformers: [fn ($value, $model) => strtoupper($value)],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [],
        );

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test reports non-callable transformer on field.
     *
     * @return void
     */
    public function testReportsNonCallableTransformerOnField(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [],
            transformers: ['not_a_function'],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [],
        );

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('App\Http\Resources\UserResource', $errors[0]->resourceClass);
        static::assertSame('name', $errors[0]->fieldKey);
        static::assertSame('Transformer at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test reports multiple non-callable transformers.
     *
     * @return void
     */
    public function testReportsMultipleNonCallableTransformers(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'email',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [],
            transformers: ['invalid_one', 'invalid_two'],
        );

        $schema = new CompiledSchema(
            fields: ['email' => $field],
            counts: [],
        );

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(2, $errors);
        static::assertSame('Transformer at index 0 is not callable', $errors[0]->defect);
        static::assertSame('Transformer at index 1 is not callable', $errors[1]->defect);
    }

    /**
     * Test no errors for schema with no transformers.
     *
     * @return void
     */
    public function testNoErrorsForSchemaWithNoTransformers(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [],
        );

        $rule   = new ValidateTransformers;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }
}
