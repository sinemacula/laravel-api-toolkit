<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\SearchStrategy;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSearchableFields;
use Tests\Fixtures\Resources\UserResource;

/**
 * Tests for the ValidateSearchableFields validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateSearchableFields::class)]
final class ValidateSearchableFieldsTest extends TestCase
{
    /**
     * Test that a scalar field declared searchable is accepted.
     *
     * @return void
     */
    public function testNoErrorsForColumnBackedField(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(searchable: 'name', strategy: SearchStrategy::SUBSTRING)],
            counts: [],
        );

        self::assertSame([], (new ValidateSearchableFields)->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that a field carrying no search declaration is accepted whatever it
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

        self::assertSame([], (new ValidateSearchableFields)->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that a computed field declared searchable is reported, since the
     * emitted predicate would name a column that does not exist.
     *
     * @return void
     */
    public function testReportsSearchableComputedField(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName', searchable: 'full_name', strategy: SearchStrategy::SUBSTRING)],
            counts: [],
        );

        $errors = (new ValidateSearchableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame('full_name', $errors[0]->fieldKey);
        self::assertSame(
            'Field is declared searchable but is computed, so there is no "full_name" column to search',
            $errors[0]->defect,
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
            fields: ['display_name' => $this->makeField(accessor: 'name', searchable: 'display_name', strategy: SearchStrategy::PREFIX)],
            counts: [],
        );

        $errors = (new ValidateSearchableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared searchable but is read through an accessor, so there is no "display_name" column to search',
            $errors[0]->defect,
        );
    }

    /**
     * Test that an accessor path matching the declared column is accepted, so
     * an aliased presentation of a real column stays searchable.
     *
     * @return void
     */
    public function testNoErrorsForAccessorPathMatchingTheDeclaredColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['display_name' => $this->makeField(accessor: 'name', searchable: 'name', strategy: SearchStrategy::SUBSTRING)],
            counts: [],
        );

        self::assertSame([], (new ValidateSearchableFields)->validate(UserResource::class, null, $schema));
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
            fields: ['name' => $this->makeField(accessor: static fn ($resource) => $resource->name, searchable: 'name', strategy: SearchStrategy::SUBSTRING)],
            counts: [],
        );

        self::assertSame([], (new ValidateSearchableFields)->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that a column declared searchable without a strategy is reported,
     * since the compiled plan drops it and the field would quietly match
     * nothing.
     *
     * @return void
     */
    public function testReportsSearchableColumnWithoutAStrategy(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(searchable: 'name')],
            counts: [],
        );

        $errors = (new ValidateSearchableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared searchable against "name" without a match strategy, so the declaration would be dropped',
            $errors[0]->defect,
        );
    }

    /**
     * Test that the missing strategy is reported ahead of the missing column,
     * so the defect the resource can act on first is the one named.
     *
     * @return void
     */
    public function testReportsTheMissingStrategyAheadOfTheMissingColumn(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName', searchable: 'full_name')],
            counts: [],
        );

        $errors = (new ValidateSearchableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared searchable against "full_name" without a match strategy, so the declaration would be dropped',
            $errors[0]->defect,
        );
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
                'name'      => $this->makeField(searchable: 'name', strategy: SearchStrategy::SUBSTRING),
                'full_name' => $this->makeField(compute: 'getFullName', searchable: 'full_name', strategy: SearchStrategy::SUBSTRING),
                'initials'  => $this->makeField(compute: 'getInitials', searchable: 'initials', strategy: SearchStrategy::PREFIX),
            ],
            counts: [],
        );

        $errors = (new ValidateSearchableFields)->validate(UserResource::class, null, $schema);

        self::assertCount(2, $errors);
        self::assertSame('full_name', $errors[0]->fieldKey);
        self::assertSame('initials', $errors[1]->fieldKey);
    }

    /**
     * Create a compiled field definition with the given search declaration.
     *
     * @param  mixed  $accessor
     * @param  mixed  $compute
     * @param  string|null  $searchable
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy|null  $strategy
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(mixed $accessor = null, mixed $compute = null, ?string $searchable = null, ?SearchStrategy $strategy = null): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor      : $accessor,
            compute       : $compute,
            relation      : null,
            resource      : null,
            fields        : null,
            constraint    : null,
            extras        : [],
            needs         : [],
            guards        : [],
            transformers  : [],
            searchable    : $searchable,
            searchStrategy: $strategy,
        );
    }
}
