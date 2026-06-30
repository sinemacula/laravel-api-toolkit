<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateAccessors;

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
final class ValidateAccessorsTest extends TestCase
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
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
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
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame('Accessor path must not be empty', $errors[0]->defect);
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
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test that null field definitions are skipped without errors.
     *
     * @return void
     */
    public function testSkipsNullFieldDefinitions(): void
    {
        $schema = $this->createSchemaWithRawFields([
            'ghost' => null,
            'bad'   => $this->makeField(''),
        ]);

        $rule   = new ValidateAccessors;
        $errors = [];

        $warnings = $this->captureWarnings(fn () => $rule->validate('App\Http\Resources\UserResource', null, $schema), $errors);

        self::assertSame([], $warnings);
        self::assertCount(1, $errors);
        self::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that validation continues past fields with null accessors and still
     * reports later defects.
     *
     * @return void
     */
    public function testContinuesPastNullAccessorFields(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'clean' => $this->makeField(null),
                'bad'   => $this->makeField(''),
            ],
            counts: [],
        );

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that validation continues past fields with callable accessors and
     * still reports later defects.
     *
     * @return void
     */
    public function testContinuesPastCallableAccessorFields(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'clean' => $this->makeField(static fn ($model) => $model->name),
                'bad'   => $this->makeField(''),
            ],
            counts: [],
        );

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that every empty accessor is reported.
     *
     * @return void
     */
    public function testReportsAllEmptyAccessors(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'first'  => $this->makeField(''),
                'second' => $this->makeField(''),
            ],
            counts: [],
        );

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('first', $errors[0]->fieldKey);
        self::assertSame('second', $errors[1]->fieldKey);
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
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateAccessors;
        $errors = $rule->validate('App\Http\Resources\UserResource', null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Create a compiled field definition with the given accessor.
     *
     * @param  mixed  $accessor
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(mixed $accessor): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: $accessor,
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
     * Run the given callback while capturing any raised PHP warnings.
     *
     * @param  callable(): array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>  $callback
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>  $errors
     * @return array<int, string>
     */
    private function captureWarnings(callable $callback, array &$errors): array
    {
        $warnings = [];

        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $errors = $callback();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    /**
     * Create a CompiledSchema with a raw fields array, bypassing the documented
     * element type via reflection.
     *
     * @param  array<string, mixed>  $fields
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function createSchemaWithRawFields(array $fields): CompiledSchema
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, $fields);
        $reflection->getProperty('counts')->setValue($schema, []);

        return $schema;
    }
}
