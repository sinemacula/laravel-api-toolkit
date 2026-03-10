<?php

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateGuards;

/**
 * Tests for the ValidateGuards validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateGuards::class)]
class ValidateGuardsTest extends TestCase
{
    /**
     * Test no errors for schema with callable guards.
     *
     * @return void
     */
    public function testNoErrorsForSchemaWithCallableGuards(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: [fn ($value, $model) => true],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test reports non-callable guard on field.
     *
     * @return void
     */
    public function testReportsNonCallableGuardOnField(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'name',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('App\Http\Resources\UserResource', $errors[0]->resourceClass);
        static::assertSame('name', $errors[0]->fieldKey);
        static::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test reports non-callable guard on count definition.
     *
     * @return void
     */
    public function testReportsNonCallableGuardOnCountDefinition(): void
    {
        $count = new CompiledCountDefinition(
            presentKey: 'comments_count',
            relation: 'comments',
            constraint: null,
            isDefault: false,
            guards: ['not_a_function'],
        );

        $schema = new CompiledSchema(
            fields: [],
            counts: ['comments_count' => $count],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\PostResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('App\Http\Resources\PostResource', $errors[0]->resourceClass);
        static::assertSame('comments_count', $errors[0]->fieldKey);
        static::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
    }

    /**
     * Test reports multiple non-callable guards.
     *
     * @return void
     */
    public function testReportsMultipleNonCallableGuards(): void
    {
        $field = new CompiledFieldDefinition(
            accessor: 'email',
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            guards: ['invalid_one', 'invalid_two'],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['email' => $field],
            counts: [],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(2, $errors);
        static::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
        static::assertSame('Guard at index 1 is not callable', $errors[1]->defect);
    }

    /**
     * Test no errors for schema with no guards.
     *
     * @return void
     */
    public function testNoErrorsForSchemaWithNoGuards(): void
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

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }
}
