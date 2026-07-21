<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateGuards;

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
final class ValidateGuardsTest extends TestCase
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
            needs: [],
            guards: [fn ($value, $model) => true],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
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
            needs: [],
            guards: ['not_a_function'],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('App\Http\Resources\UserResource', $errors[0]->resourceClass);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
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

        self::assertCount(1, $errors);
        self::assertSame('App\Http\Resources\PostResource', $errors[0]->resourceClass);
        self::assertSame('comments_count', $errors[0]->fieldKey);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
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
            needs: [],
            guards: ['invalid_one', 'invalid_two'],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['email' => $field],
            counts: [],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('Guard at index 0 is not callable', $errors[0]->defect);
        self::assertSame('Guard at index 1 is not callable', $errors[1]->defect);
    }

    /**
     * Test that field guard errors and count definition guard errors are both
     * accumulated rather than the count loop discarding earlier errors.
     *
     * @return void
     */
    public function testAccumulatesFieldAndCountGuardErrors(): void
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

        $count = new CompiledCountDefinition(
            presentKey: 'comments_count',
            relation: 'comments',
            constraint: null,
            isDefault: false,
            guards: ['not_a_function'],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: ['comments_count' => $count],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\PostResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('comments_count', $errors[1]->fieldKey);
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
            needs: [],
            guards: [],
            transformers: [],
        );

        $schema = new CompiledSchema(
            fields: ['name' => $field],
            counts: [],
        );

        $rule   = new ValidateGuards;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }
}
