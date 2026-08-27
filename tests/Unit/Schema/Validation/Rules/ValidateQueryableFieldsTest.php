<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateQueryableFields;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the ValidateQueryableFields validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateQueryableFields::class)]
final class ValidateQueryableFieldsTest extends TestCase
{
    /**
     * Test that a scalar field declared filterable and sortable is accepted.
     *
     * @return void
     */
    public function testNoErrorsForColumnBackedField(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(filterable: 'name', sortable: 'name')],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test that a field carrying no query declaration is accepted whatever it
     * resolves through.
     *
     * @return void
     */
    public function testNoErrorsForUndeclaredComputedField(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName')],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test that a computed field declared filterable is reported, since the
     * emitted clause would name a column that does not exist.
     *
     * @return void
     */
    public function testReportsFilterableComputedField(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName', filterable: 'full_name')],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('full_name', $errors[0]->fieldKey);
        self::assertSame(
            'Field is declared filterable but is computed, so there is no "full_name" column to query',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a computed field declared sortable is reported under the sort
     * declaration rather than the filter one.
     *
     * @return void
     */
    public function testReportsSortableComputedField(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName', sortable: 'full_name')],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared sortable but is computed, so there is no "full_name" column to query',
            $errors[0]->defect,
        );
    }

    /**
     * Test that both declarations on the same computed field are reported
     * independently.
     *
     * @return void
     */
    public function testReportsBothDeclarationsOnTheSameField(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName', filterable: 'full_name', sortable: 'full_name')],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(2, $errors);
        self::assertSame(
            'Field is declared filterable but is computed, so there is no "full_name" column to query',
            $errors[0]->defect,
        );
        self::assertSame(
            'Field is declared sortable but is computed, so there is no "full_name" column to query',
            $errors[1]->defect,
        );
    }

    /**
     * Test that a field reading a different path from the column it declares is
     * reported.
     *
     * @return void
     */
    public function testReportsAccessorPathThatDiffersFromTheDeclaredColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['display_name' => $this->makeField(accessor: 'name', filterable: 'display_name')],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared filterable but is read through an accessor, so there is no "display_name" column to query',
            $errors[0]->defect,
        );
    }

    /**
     * Test that an accessor path matching the declared column is accepted, so
     * an aliased presentation of a real column stays queryable.
     *
     * @return void
     */
    public function testNoErrorsForAccessorPathMatchingTheDeclaredColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['display_name' => $this->makeField(accessor: 'name', filterable: 'name')],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test that a closure accessor is accepted, since the path it reads cannot
     * be resolved from the schema alone.
     *
     * @return void
     */
    public function testNoErrorsForClosureAccessor(): void
    {
        $schema = new CompiledSchema(
            fields: ['created_at' => $this->makeField(accessor: static fn ($resource) => $resource->created_at, sortable: 'created_at')],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test that every offending field is reported, not just the first.
     *
     * @return void
     */
    public function testReportsEveryOffendingField(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'name'      => $this->makeField(filterable: 'name'),
                'full_name' => $this->makeField(compute: 'getFullName', filterable: 'full_name'),
                'initials'  => $this->makeField(compute: 'getInitials', sortable: 'initials'),
            ],
            counts: [],
        );

        $errors = (new ValidateQueryableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('full_name', $errors[0]->fieldKey);
        self::assertSame('initials', $errors[1]->fieldKey);
    }

    /**
     * Create a compiled field definition with the given query declarations.
     *
     * @param  mixed  $accessor
     * @param  mixed  $compute
     * @param  string|null  $filterable
     * @param  string|null  $sortable
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(mixed $accessor = null, mixed $compute = null, ?string $filterable = null, ?string $sortable = null): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: $accessor,
            compute: $compute,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
            filterable: $filterable,
            sortable: $sortable,
        );
    }
}
