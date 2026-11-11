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
 * The column listing is stubbed so every possible answer runs on any engine.
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
     * Test that a filterable and sortable field on a real column is accepted.
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
     * Test that a resource declaring nothing queryable never reads the columns.
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
     * Test that a computed filterable field is reported exactly once.
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
     * Test that a computed sortable field is reported as a sort defect.
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
     * Test that both declarations on one computed field are reported.
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
     * Test that an accessor path differing from the column is reported.
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
     * Test that an accessor path matching the declared column is accepted.
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
     * Test that a closure accessor declaring a real column is accepted.
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
     * Test that a closure accessor declaring a missing column is reported.
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
     * Test that a filterable field on a missing column is reported.
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
     * Test that a filterable declaration with no capability is reported.
     *
     * The compiled surface drops the column, so every filter on it is refused.
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
     * Test that a sortable declaration on the same field is judged on its own.
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
     * Test that one column declared with differing capabilities is reported.
     *
     * The filter surface is keyed by column, so the last declaration would win.
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
     * Test that every conflicting column is reported, past undeclared fields.
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
     * Test that a field repeating a capability does not end the walk.
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
     * Test that a capability-less declaration is not weighed as a conflict.
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
     * Test that a sound filterable declaration does not end the walk.
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
     * Test that two fields repeating one column's capability are accepted.
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
     * Test that an unreadable column listing proves nothing.
     *
     * Only a missing table or uninspectable connection gives an empty listing.
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
     * Test that a resource with no model behind it is passed over.
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
     * Test that a mapped class that is not a model is passed over.
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
     * Test that a schema defect is reported even with no model behind it.
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
     * Test that every offending field is reported, past undeclared fields.
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
     * Build the rule over the given column listing, empty meaning unreadable.
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
