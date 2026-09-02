<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Enums\Capability;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateQueryableFields;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateQueryableFields validation rule.
 *
 * The column listing behind the rule is supplied rather than read, so every
 * answer a connection can give - a listing naming the column, one that does
 * not, and one that could not be read at all - is exercised on whichever engine
 * the suite runs against.
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
     * Test that a scalar field declared filterable and sortable against a
     * column the table carries is accepted.
     *
     * @return void
     */
    public function testNoErrorsForColumnBackedField(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(filterable: 'name', sortable: 'name')],
            counts: [],
        );

        self::assertSame([], $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a resource declaring nothing queryable is passed over, so the
     * column listing is never read for a resource with no query surface.
     *
     * @return void
     */
    public function testPassesOverAResourceDeclaringNothingQueryable(): void
    {
        $introspector = self::createMock(SchemaIntrospectionProvider::class);

        $introspector->expects(self::never())->method('getColumns');

        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName')],
            counts: [],
        );

        self::assertSame([], (new ValidateQueryableFields($introspector))->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a computed field declared filterable is reported, since the
     * emitted clause would name a column that does not exist.
     *
     * The listing does not name the column either, so the single error also
     * pins that one declaration yields one defect rather than two readings of
     * the same one.
     *
     * @return void
     */
    public function testReportsFilterableComputedField(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName', filterable: 'full_name')],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame(UserResource::class, $errors[0]->resourceClass);
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

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

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

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

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

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

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

        self::assertSame([], $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a closure accessor declaring a column the table carries is
     * accepted, since the path it reads is opaque and the column is real.
     *
     * @return void
     */
    public function testNoErrorsForClosureAccessorAgainstAColumnTheTableCarries(): void
    {
        $schema = new CompiledSchema(
            fields: ['created_at' => $this->makeField(accessor: static fn ($resource) => $resource->created_at, sortable: 'created_at')],
            counts: [],
        );

        self::assertSame([], $this->rule(['id', 'created_at'])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a closure accessor declaring a column the table does not carry
     * is reported, which is the half the schema alone cannot prove.
     *
     * @return void
     */
    public function testReportsAClosureAccessorAgainstAColumnTheTableLacks(): void
    {
        $schema = new CompiledSchema(
            fields: ['legacy_name' => $this->makeField(accessor: static fn ($resource) => $resource->name, sortable: 'legacy_name')],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('legacy_name', $errors[0]->fieldKey);
        self::assertSame(
            'Field is declared sortable against "legacy_name", and table "users" carries no such column',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a scalar field declared filterable against a column the table
     * does not carry is reported.
     *
     * @return void
     */
    public function testReportsAFilterableColumnTheTableLacks(): void
    {
        $schema = new CompiledSchema(
            fields: ['nickname' => $this->makeField(filterable: 'nickname')],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared filterable against "nickname", and table "users" carries no such column',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a filterable declaration carrying no capability is reported,
     * since the compiled surface drops the column and every filter on it is
     * then refused as unpermitted.
     *
     * @return void
     */
    public function testReportsAFilterableColumnDeclaredWithoutACapability(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(filterable: 'name', capability: null)],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame(
            'Field is declared filterable against "name" without a capability, so the declaration would be dropped',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a sortable declaration on the same field is judged on its own,
     * so the missing capability is reported against the filterable declaration
     * alone rather than standing in for both.
     *
     * @return void
     */
    public function testReportsOnlyTheFilterableDeclarationForAMissingCapability(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(filterable: 'name', sortable: 'name', capability: null)],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared filterable against "name" without a capability, so the declaration would be dropped',
            $errors[0]->defect,
        );
    }

    /**
     * Test that two fields declaring one column with different capabilities are
     * reported, since the filter surface is keyed by column and the declaration
     * compiled last would otherwise decide what it answers.
     *
     * @return void
     */
    public function testReportsTwoCapabilitiesDeclaredForTheSameColumn(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'status' => $this->makeField(filterable: 'status', capability: Capability::EXACT),
                'state'  => $this->makeField(filterable: 'status', capability: Capability::RANGE),
            ],
            counts: [],
        );

        $errors = $this->rule(['id', 'status'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('state', $errors[0]->fieldKey);
        self::assertSame(
            'Field is declared filterable against "status" with the "range" capability, and field "status" declares '
            . 'the same column with "exact", so the operators the column answers are left to declaration order',
            $errors[0]->defect,
        );
    }

    /**
     * Test that every column declared with two capabilities is reported, and
     * that a field declaring nothing filterable is passed over rather than
     * ending the walk.
     *
     * @return void
     */
    public function testReportsEveryColumnDeclaredWithTwoCapabilities(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'created_at' => $this->makeField(sortable: 'created_at'),
                'status'     => $this->makeField(filterable: 'status', capability: Capability::EXACT),
                'state'      => $this->makeField(filterable: 'status', capability: Capability::RANGE),
                'kind'       => $this->makeField(filterable: 'kind', capability: Capability::EXACT),
                'sort'       => $this->makeField(filterable: 'kind', capability: Capability::ENUM),
            ],
            counts: [],
        );

        $errors = $this->rule(['id', 'status', 'kind', 'created_at'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(2, $errors);
        self::assertSame('state', $errors[0]->fieldKey);
        self::assertSame('sort', $errors[1]->fieldKey);
    }

    /**
     * Test that a field repeating the capability another already declared does
     * not end the walk, so a third field changing it is still reported.
     *
     * @return void
     */
    public function testReadsPastAFieldRepeatingACapabilityToOneThatChangesIt(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'status'    => $this->makeField(filterable: 'status', capability: Capability::EXACT),
                'state'     => $this->makeField(filterable: 'status', capability: Capability::EXACT),
                'condition' => $this->makeField(filterable: 'status', capability: Capability::RANGE),
            ],
            counts: [],
        );

        $errors = $this->rule(['id', 'status'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('condition', $errors[0]->fieldKey);
    }

    /**
     * Test that a declaration carrying no capability is not weighed against the
     * field that does govern the column, since it declares no capability to
     * conflict with and is already reported as dropped.
     *
     * @return void
     */
    public function testPassesOverACapabilitylessDeclarationWhenAnotherFieldGovernsTheColumn(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'name'  => $this->makeField(filterable: 'name', capability: null),
                'label' => $this->makeField(filterable: 'name', capability: Capability::EXACT),
            ],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame(
            'Field is declared filterable against "name" without a capability, so the declaration would be dropped',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a sound filterable declaration does not end the walk over the
     * field's declarations, so the sortable one beside it is still judged.
     *
     * @return void
     */
    public function testReportsASortableDefectBesideASoundFilterableDeclaration(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(filterable: 'name', sortable: 'nickname')],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared sortable against "nickname", and table "users" carries no such column',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a second field repeating the same capability for one column is
     * accepted, since an alias naming the surface the first field declared
     * leaves nothing to decide.
     *
     * @return void
     */
    public function testAcceptsTwoFieldsDeclaringOneColumnWithTheSameCapability(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'status' => $this->makeField(filterable: 'status', capability: Capability::EXACT),
                'state'  => $this->makeField(filterable: 'status', capability: Capability::EXACT),
            ],
            counts: [],
        );

        self::assertSame([], $this->rule(['id', 'status'])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a listing that could not be read proves nothing, so a
     * declaration is left alone rather than refused.
     *
     * A listing comes back empty only where the connection could not be
     * inspected or the table is not there to inspect, since no table carries no
     * columns at all.
     *
     * @return void
     */
    public function testStaysSilentAgainstAnUnreadableColumnListing(): void
    {
        $schema = new CompiledSchema(
            fields: ['nickname' => $this->makeField(filterable: 'nickname')],
            counts: [],
        );

        self::assertSame([], $this->rule([])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a resource with no model behind it is passed over, since there
     * is no table whose columns could be read.
     *
     * @return void
     */
    public function testPassesOverAResourceWithNoModel(): void
    {
        $schema = new CompiledSchema(
            fields: ['nickname' => $this->makeField(filterable: 'nickname')],
            counts: [],
        );

        self::assertSame([], $this->rule(['id', 'name'])->validate(UserResource::class, null, $schema));
    }

    /**
     * Test that a mapped class that is not an Eloquent model is passed over
     * rather than instantiated.
     *
     * @return void
     */
    public function testPassesOverAMappedClassThatIsNotAModel(): void
    {
        $schema = new CompiledSchema(
            fields: ['nickname' => $this->makeField(filterable: 'nickname')],
            counts: [],
        );

        self::assertSame([], $this->rule(['id', 'name'])->validate(UserResource::class, UserResource::class, $schema));
    }

    /**
     * Test that the schema defect is still reported where no model is behind
     * the resource, since it is decided from the schema alone.
     *
     * @return void
     */
    public function testReportsASchemaDefectWithNoModelBehindTheResource(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(compute: 'getFullName', filterable: 'full_name')],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared filterable but is computed, so there is no "full_name" column to query',
            $errors[0]->defect,
        );
    }

    /**
     * Test that every offending field is reported, not just the first, and that
     * a field declaring nothing is passed over rather than ending the walk.
     *
     * @return void
     */
    public function testReportsEveryOffendingField(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'avatar_url' => $this->makeField(compute: 'getAvatarUrl'),
                'name'       => $this->makeField(filterable: 'name'),
                'full_name'  => $this->makeField(compute: 'getFullName', filterable: 'full_name'),
                'initials'   => $this->makeField(compute: 'getInitials', sortable: 'initials'),
            ],
            counts: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(2, $errors);
        self::assertSame('full_name', $errors[0]->fieldKey);
        self::assertSame('initials', $errors[1]->fieldKey);
    }

    /**
     * Build the rule over an introspection provider reporting the given column
     * listing, where an empty listing stands for a connection that could not be
     * read.
     *
     * @param  array<int, string>  $columns
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateQueryableFields
     */
    private function rule(array $columns): ValidateQueryableFields
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('getColumns')->willReturn($columns);

        return new ValidateQueryableFields($introspector);
    }

    /**
     * Create a compiled field definition with the given query declarations.
     *
     * @param  mixed  $accessor
     * @param  mixed  $compute
     * @param  string|null  $filterable
     * @param  string|null  $sortable
     * @param  \SineMacula\ApiToolkit\Enums\Capability|null  $capability
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(
        mixed $accessor = null,
        mixed $compute = null,
        ?string $filterable = null,
        ?string $sortable = null,
        ?Capability $capability = Capability::EXACT,
    ): CompiledFieldDefinition {
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
            filterCapability: $filterable === null ? null : $capability,
            sortable: $sortable,
        );
    }
}
