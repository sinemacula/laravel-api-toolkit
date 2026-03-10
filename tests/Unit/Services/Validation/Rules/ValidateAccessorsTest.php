<?php

namespace Tests\Unit\Services\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateAccessors;

/**
 * Tests for the ValidateAccessors validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateAccessors::class)]
class ValidateAccessorsTest extends TestCase
{
    /**
     * Test that no errors are returned for valid non-empty string accessors.
     *
     * @return void
     */
    public function testNoErrorsForValidStringAccessors(): void
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

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that an error is reported for an empty string accessor.
     *
     * @return void
     */
    public function testReportsEmptyStringAccessor(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'name' => new CompiledFieldDefinition(
                    accessor: '',
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

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertCount(1, $errors);
        static::assertSame('name', $errors[0]->fieldKey);
        static::assertSame('Accessor path must not be empty', $errors[0]->defect);
    }

    /**
     * Test that callable accessors are skipped without errors.
     *
     * @return void
     */
    public function testSkipsCallableAccessors(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'full_name' => new CompiledFieldDefinition(
                    accessor: fn ($model) => $model->first_name . ' ' . $model->last_name,
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

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test that null accessors are skipped without errors.
     *
     * @return void
     */
    public function testSkipsNullAccessors(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'full_label' => new CompiledFieldDefinition(
                    accessor: null,
                    compute: fn ($resource) => $resource->name,
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

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        static::assertSame([], $errors);
    }
}
