<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility;
use SineMacula\ApiToolkit\Schema\Introspection\IndexEligibilityInspector;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateIndexBacking;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Search\StubIndexEligibilityInspector;
use Tests\TestCase;

/**
 * Tests for the ValidateIndexBacking validation rule.
 *
 * The catalogue is stubbed so every reportable shape runs on any engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateIndexBacking::class)]
final class ValidateIndexBackingTest extends TestCase
{
    /**
     * Test that nothing sortable means the catalogue is never read.
     *
     * @return void
     */
    public function testPassesOverAResourceDeclaringNothingSortable(): void
    {
        $introspector = self::createMock(SchemaIntrospectionProvider::class);

        $introspector->expects(self::never())->method('getIndexes');

        $schema = new CompiledSchema(fields: ['name' => $this->makeField()], counts: []);

        self::assertSame([], (new ValidateIndexBacking($introspector))->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a sortable declaration with no model behind it proves nothing.
     *
     * @return void
     */
    public function testPassesOverAResourceWithNoModel(): void
    {
        self::assertSame([], $this->rule([])->validate(UserResource::class, null, $this->schema('name')));
    }

    /**
     * Test that a mapped class that is not a model proves nothing.
     *
     * @return void
     */
    public function testPassesOverAMappedClassThatIsNotAModel(): void
    {
        self::assertSame([], $this->rule([])->validate(UserResource::class, UserResource::class, $this->schema('name')));
    }

    /**
     * Test that a schema-only defect is reported even with no model behind it.
     *
     * @return void
     */
    public function testReportsASchemaDefectWithNoModelBehindTheResource(): void
    {
        $schema = $this->schema('name', indexedBy: 'users_lower_name_index', unindexedReason: 'Bounded table');

        $errors = $this->rule([])->validate(UserResource::class, null, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field declares both a backing index and an index exemption, so neither governs the sort',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a sortable column an index leads with is accepted.
     *
     * @return void
     */
    public function testAcceptsASortableColumnAnIndexLeadsWith(): void
    {
        $rule = $this->rule([new IndexDefinition('users_name_index', ['name'], 'btree')]);

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that a sortable column leading a composite index is accepted.
     *
     * @return void
     */
    public function testAcceptsASortableColumnLeadingACompositeIndex(): void
    {
        $rule = $this->rule([new IndexDefinition('users_status_name_index', ['status', 'name'], 'btree')]);

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->schema('status')));
    }

    /**
     * Test that a sortable column named second in a composite index is refused.
     *
     * The column is in the index but cannot be ordered by on its own.
     *
     * @return void
     */
    public function testRefusesASortableColumnNamedSecondInACompositeIndex(): void
    {
        $rule = $this->rule([new IndexDefinition('users_status_name_index', ['status', 'name'], 'btree')]);

        $errors = $rule->validate(UserResource::class, User::class, $this->schema('name'));

        self::assertCount(1, $errors);
        self::assertSame(UserResource::class, $errors[0]->resourceClass);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame(
            'Field is declared sortable against "name", and no ordered index on table "users" leads with that column',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a sortable column with no index over it at all is refused.
     *
     * @return void
     */
    public function testRefusesASortableColumnWithNoIndexOverIt(): void
    {
        $rule = $this->rule([new IndexDefinition('users_email_unique', ['email'], 'btree')]);

        $errors = $rule->validate(UserResource::class, User::class, $this->schema('name'));

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared sortable against "name", and no ordered index on table "users" leads with that column',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a verified empty catalogue refuses the declaration.
     *
     * @return void
     */
    public function testRefusesASortableColumnAgainstAVerifiedEmptyCatalogue(): void
    {
        $errors = $this->rule([])->validate(UserResource::class, User::class, $this->schema('name'));

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared sortable against "name", and no ordered index on table "users" leads with that column',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a catalogue that could not be inspected is passed over.
     *
     * @return void
     */
    public function testPassesOverAnUnverifiableCatalogueRatherThanRefusingIt(): void
    {
        self::assertSame([], $this->rule(null)->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that an unordered index kind is refused even when it leads.
     *
     * @return void
     */
    public function testRefusesAnIndexOfAKindThatHoldsNoOrder(): void
    {
        $rule = $this->rule([new IndexDefinition('users_name_trgm', ['name'], 'gin')]);

        $errors = $rule->validate(UserResource::class, User::class, $this->schema('name'));

        self::assertCount(1, $errors);
        self::assertSame(
            'Field is declared sortable against "name", and no ordered index on table "users" leads with that column',
            $errors[0]->defect,
        );
    }

    /**
     * Test that an index reported without a kind is read as ordered.
     *
     * @return void
     */
    public function testAcceptsAnIndexTheConnectionReportsWithoutAKind(): void
    {
        $rule = $this->rule([new IndexDefinition('users_name_index', ['name'], null)]);

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that the walk looks past an unordered index to an ordered one.
     *
     * @return void
     */
    public function testReadsPastAnIndexOfAnotherKindLeadingWithTheColumn(): void
    {
        $rule = $this->rule([
            new IndexDefinition('users_name_trgm', ['name'], 'gin'),
            new IndexDefinition('users_name_index', ['name'], 'btree'),
        ]);

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that every sortable column is reported, past undeclared fields.
     *
     * @return void
     */
    public function testReportsEveryUnbackedSortableColumn(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'email'      => $this->makeField(),
                'name'       => $this->makeField('name'),
                'created_at' => $this->makeField('created_at'),
            ],
            counts: [],
            sortableColumns: ['name', 'created_at'],
        );

        $errors = $this->rule([])->validate(UserResource::class, User::class, $schema);

        self::assertSame(['name', 'created_at'], array_map(static fn ($error): string => $error->fieldKey, $errors));
    }

    /**
     * Test that a column naming an index the table carries is accepted.
     *
     * @return void
     */
    public function testAcceptsASortableColumnDeclaredAgainstANamedIndexTheTableCarries(): void
    {
        $rule = $this->rule([new IndexDefinition('users_lower_name_index', [], 'btree')]);

        $schema = $this->schema('name', indexedBy: 'users_lower_name_index');

        self::assertSame([], $rule->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a named index is matched case-insensitively.
     *
     * @return void
     */
    public function testMatchesTheNamedIndexWithoutRegardToCase(): void
    {
        $rule = $this->rule([new IndexDefinition('users_lower_name_index', [], 'btree')]);

        $schema = $this->schema('name', indexedBy: 'Users_Lower_Name_Index');

        self::assertSame([], $rule->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a column naming an index the table lacks is refused.
     *
     * @return void
     */
    public function testRefusesASortableColumnDeclaredAgainstAnIndexTheTableDoesNotCarry(): void
    {
        $rule = $this->rule([new IndexDefinition('users_name_index', ['name'], 'btree')]);

        $errors = $rule->validate(UserResource::class, User::class, $this->schema('name', indexedBy: 'users_lower_name_index'));

        self::assertCount(1, $errors);
        self::assertSame(
            'Field declares the "users_lower_name_index" index behind sortable column "name", and table "users" carries no index of that name',
            $errors[0]->defect,
        );
    }

    /**
     * Test that an exempt column is accepted without consulting the catalogue.
     *
     * @return void
     */
    public function testAcceptsASortableColumnCarryingAnExemption(): void
    {
        $schema = $this->schema('name', unindexedReason: 'The table is bounded at a few hundred rows');

        self::assertSame([], $this->rule([])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that declaring both a backing index and an exemption is reported.
     *
     * @return void
     */
    public function testReportsAFieldDeclaringBothAnIndexAndAnExemption(): void
    {
        $schema = $this->schema('name', indexedBy: 'users_lower_name_index', unindexedReason: 'Bounded table');

        $errors = $this->rule([])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field declares both a backing index and an index exemption, so neither governs the sort',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a backing index on a field with nothing sortable is reported.
     *
     * @return void
     */
    public function testReportsANamedIndexOnAFieldThatIsNotSortable(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(indexedBy: 'users_name_index')],
            counts: [],
        );

        $errors = $this->rule([])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('name', $errors[0]->fieldKey);
        self::assertSame(
            'Field declares index backing but is not declared sortable, so the declaration governs nothing',
            $errors[0]->defect,
        );
    }

    /**
     * Test that an exemption on an unsortable field gets its own error.
     *
     * @return void
     */
    public function testReportsAnExemptionOnAFieldThatIsNotSortable(): void
    {
        $schema = new CompiledSchema(
            fields: ['name' => $this->makeField(unindexedReason: 'Bounded table')],
            counts: [],
        );

        $errors = $this->rule([])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field declares an index exemption but is not declared sortable, so the exemption governs nothing',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a declaration defect is reported without a readable catalogue.
     *
     * @return void
     */
    public function testReportsADeclarationDefectAgainstAnUnverifiableCatalogue(): void
    {
        $schema = $this->schema('name', indexedBy: 'users_lower_name_index', unindexedReason: 'Bounded table');

        $errors = $this->rule(null)->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame(
            'Field declares both a backing index and an index exemption, so neither governs the sort',
            $errors[0]->defect,
        );
    }

    /**
     * Test that a column whose only leading index is disregarded is refused.
     *
     * The catalogue reports such an index exactly as it reports a usable one.
     *
     * @return void
     */
    public function testRefusesASortableColumnLedOnlyByADisregardedIndex(): void
    {
        $indexes = [new IndexDefinition('users_name_index', ['name'], 'btree')];

        $rule = $this->rule($indexes, new IndexEligibility(['users_name_index']));

        self::assertSame(
            ['Field is declared sortable against "name", and no ordered index on table "users" leads with that column'],
            array_map(static fn ($error): string => $error->defect, $rule->validate(UserResource::class, User::class, $this->schema('name'))),
        );

        // Without the report the same catalogue proves the column.
        self::assertSame([], $this->rule($indexes)->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that an index keyed on an expression does not prove the column.
     *
     * The catalogue omits the expression, so the next column reads as leading.
     *
     * @return void
     */
    public function testRefusesASortableColumnLedOnlyByAnExpressionKeyedIndex(): void
    {
        $indexes = [new IndexDefinition('users_lower_name_index', ['created_at'], 'btree')];

        $rule = $this->rule($indexes, new IndexEligibility([], [], ['users_lower_name_index']));

        self::assertNotSame([], $rule->validate(UserResource::class, User::class, $this->schema('created_at')));
        self::assertSame([], $this->rule($indexes)->validate(UserResource::class, User::class, $this->schema('created_at')));
    }

    /**
     * Test that a column is refused where its leading index is partial.
     *
     * An ordered read has no predicate, so a partial index cannot serve it.
     *
     * @return void
     */
    public function testRefusesASortableColumnLedOnlyByARestrictedIndex(): void
    {
        $indexes = [new IndexDefinition('users_live_name_index', ['name'], 'btree')];

        $rule = $this->rule($indexes, new IndexEligibility([], ['users_live_name_index']));

        self::assertNotSame([], $rule->validate(UserResource::class, User::class, $this->schema('name')));
        self::assertSame([], $this->rule($indexes)->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that a column is refused where its only index lacks its order.
     *
     * A truncated or differently collated key still names the column first.
     *
     * @return void
     */
    public function testRefusesASortableColumnLedOnlyByAnIndexLackingItsOrder(): void
    {
        $indexes = [new IndexDefinition('users_name_prefix_index', ['name'], 'btree')];

        $rule = $this->rule($indexes, new IndexEligibility([], [], [], ['users_name_prefix_index']));

        self::assertSame(
            ['Field is declared sortable against "name", and no ordered index on table "users" leads with that column'],
            array_map(static fn ($error): string => $error->defect, $rule->validate(UserResource::class, User::class, $this->schema('name'))),
        );
    }

    /**
     * Test that an ordinary index proves the column beside one lacking order.
     *
     * @return void
     */
    public function testAcceptsAnOrderedIndexBesideOneLackingTheColumnsOrder(): void
    {
        $rule = $this->rule([
            new IndexDefinition('users_name_prefix_index', ['name'], 'btree'),
            new IndexDefinition('users_name_index', ['name'], 'btree'),
        ], new IndexEligibility([], [], [], ['users_name_prefix_index']));

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that a named index lacking the column's order is vouched for.
     *
     * Only the engine's refusal defeats the named override.
     *
     * @return void
     */
    public function testAcceptsANamedIndexLackingTheColumnsOrder(): void
    {
        $rule = $this->rule(
            [new IndexDefinition('users_name_prefix_index', ['name'], 'btree')],
            new IndexEligibility([], [], [], ['users_name_prefix_index']),
        );

        $schema = $this->schema('name', indexedBy: 'users_name_prefix_index');

        self::assertSame([], $rule->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a collated-apart index is refused unless named outright.
     *
     * @return void
     */
    public function testRefusesAnIndexCollatedApartUnlessItIsNamed(): void
    {
        $rule = $this->rule(
            [new IndexDefinition('users_name_c_index', ['name'], 'btree')],
            new IndexEligibility([], [], [], [], ['users_name_c_index']),
        );

        self::assertSame(
            ['Field is declared sortable against "name", and no ordered index on table "users" leads with that column'],
            array_map(static fn ($error): string => $error->defect, $rule->validate(UserResource::class, User::class, $this->schema('name'))),
        );

        $schema = $this->schema('name', indexedBy: 'users_name_c_index');

        self::assertSame([], $rule->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that an exemption still stands over an index lacking the order.
     *
     * @return void
     */
    public function testAcceptsAnExemptionOverAnIndexLackingTheColumnsOrder(): void
    {
        $rule = $this->rule(
            [new IndexDefinition('users_name_prefix_index', ['name'], 'btree')],
            new IndexEligibility([], [], [], ['users_name_prefix_index']),
        );

        $schema = $this->schema('name', unindexedReason: 'The table is bounded at a few hundred rows');

        self::assertSame([], $rule->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a refused index does not hide a later one proving the column.
     *
     * @return void
     */
    public function testKeepsReadingPastAnIndexItPassesOver(): void
    {
        $eligibility = new IndexEligibility(['users_name_hidden']);

        $rule = $this->rule([
            new IndexDefinition('users_name_hidden', ['name'], 'btree'),
            new IndexDefinition('users_name_index', ['name'], 'btree'),
        ], $eligibility);

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->schema('name')));

        // With only the passed-over index declared, the column is refused.
        $only = $this->rule([new IndexDefinition('users_name_hidden', ['name'], 'btree')], $eligibility);

        self::assertNotSame([], $only->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that eligibility is not asked for when the catalogue is unreadable.
     *
     * @return void
     */
    public function testAnUnreadableCatalogueIsNotAskedAboutItsIndexes(): void
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('getIndexes')->willReturn(null);

        $inspector = self::createMock(IndexEligibilityInspector::class);

        $inspector->expects(self::never())->method('inspect');

        $rule = new ValidateIndexBacking($introspector, $inspector);

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that a disregarded named index is refused as carried, not missing.
     *
     * @return void
     */
    public function testRefusesANamedIndexTheConnectionWillNotPlanAgainst(): void
    {
        $rule = $this->rule(
            [new IndexDefinition('users_name_index', ['name'], 'btree')],
            new IndexEligibility(['users_name_index']),
        );

        $errors = $rule->validate(UserResource::class, User::class, $this->schema('name', indexedBy: 'users_name_index'));

        self::assertSame(
            ['Field declares the "users_name_index" index behind sortable column "name", and table "users" carries it, but the connection will not plan against it'],
            array_map(static fn ($error): string => $error->defect, $errors),
        );
    }

    /**
     * Test that a named expression-keyed or partial index is accepted.
     *
     * @return void
     */
    public function testAcceptsANamedIndexTheColumnListCannotDescribe(): void
    {
        $rule = $this->rule(
            [new IndexDefinition('users_lower_name_index', [], 'btree')],
            new IndexEligibility([], ['users_lower_name_index'], ['users_lower_name_index']),
        );

        $schema = $this->schema('name', indexedBy: 'users_lower_name_index');

        self::assertSame([], $rule->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Build the rule over the given catalogue, null meaning an unreadable one.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null  $indexes
     * @param  \SineMacula\ApiToolkit\Schema\Introspection\IndexEligibility  $eligibility
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateIndexBacking
     */
    private function rule(?array $indexes, IndexEligibility $eligibility = new IndexEligibility): ValidateIndexBacking
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('getIndexes')->willReturn($indexes);

        return new ValidateIndexBacking($introspector, new StubIndexEligibilityInspector($eligibility));
    }

    /**
     * Build a compiled schema declaring one sortable column.
     *
     * @param  string  $column
     * @param  string|null  $indexedBy
     * @param  string|null  $unindexedReason
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function schema(string $column, ?string $indexedBy = null, ?string $unindexedReason = null): CompiledSchema
    {
        return new CompiledSchema(
            fields: [$column => $this->makeField($column, $indexedBy, $unindexedReason)],
            counts: [],
            sortableColumns: [$column],
        );
    }

    /**
     * Create a compiled field definition with the given sort declaration.
     *
     * @param  string|null  $sortable
     * @param  string|null  $indexedBy
     * @param  string|null  $unindexedReason
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(?string $sortable = null, ?string $indexedBy = null, ?string $unindexedReason = null): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor       : null,
            compute        : null,
            relation       : null,
            resource       : null,
            fields         : null,
            constraint     : null,
            extras         : [],
            needs          : [],
            guards         : [],
            transformers   : [],
            sortable       : $sortable,
            indexedBy      : $indexedBy,
            unindexedReason: $unindexedReason,
        );
    }
}
