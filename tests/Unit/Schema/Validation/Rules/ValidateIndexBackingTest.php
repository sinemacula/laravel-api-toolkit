<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateIndexBacking;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateIndexBacking validation rule.
 *
 * The catalogue behind the rule is supplied rather than read, so every shape a
 * connection can report - an index of a kind that holds no order, a column
 * named second, a catalogue that is empty, and one that could not be read at
 * all - is exercised on whichever engine the suite runs against.
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
     * Test that a resource declaring nothing sortable is passed over, so the
     * catalogue is never read for a resource with no sort surface.
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
     * Test that a sortable declaration with no model behind the resource proves
     * nothing, since there is no table whose catalogue could be read.
     *
     * @return void
     */
    public function testPassesOverAResourceWithNoModel(): void
    {
        self::assertSame([], $this->rule([])->validate(UserResource::class, null, $this->schema('name')));
    }

    /**
     * Test that a mapped class that is not an Eloquent model proves nothing
     * rather than being instantiated for a table it does not have.
     *
     * @return void
     */
    public function testPassesOverAMappedClassThatIsNotAModel(): void
    {
        self::assertSame([], $this->rule([])->validate(UserResource::class, UserResource::class, $this->schema('name')));
    }

    /**
     * Test that a defect decided from the schema alone is reported even with no
     * model behind the resource, since contradicting overrides need no table to
     * be read as contradicting.
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
     * Test that a sortable column leading a composite index is accepted, since
     * the leading column is the key prefix an ordered read follows.
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
     * This is the case that separates a leading-column check from a membership
     * check: the column is covered by the index and still cannot be ordered by
     * on its own, so a rule reading membership would pass a declaration the
     * database cannot serve.
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
     * Test that a catalogue the connection read and found empty refuses the
     * declaration, since an empty catalogue is a real answer.
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
     * Test that a catalogue the connection could not be inspected for is passed
     * over rather than refused, so a developer booting with no database behind
     * the application does not get a hard failure.
     *
     * An unverifiable catalogue and a verified empty one must never be
     * conflated: reading the first as the second turns every such boot into a
     * refusal, and reading the second as the first stops the rule refusing
     * anything at all.
     *
     * @return void
     */
    public function testPassesOverAnUnverifiableCatalogueRatherThanRefusingIt(): void
    {
        self::assertSame([], $this->rule(null)->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that an index of a kind that holds no order is refused even when it
     * leads with the column, since it cannot answer an ordered read.
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
     * Test that an index the connection reports without a kind is accepted, so
     * a connection that distinguishes no kinds is read as reporting ordered
     * indexes rather than unusable ones.
     *
     * @return void
     */
    public function testAcceptsAnIndexTheConnectionReportsWithoutAKind(): void
    {
        $rule = $this->rule([new IndexDefinition('users_name_index', ['name'], null)]);

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->schema('name')));
    }

    /**
     * Test that an index leading with the column is still found when an index
     * of a kind that holds no order leads with it first, so the walk does not
     * stop at the first index over the column.
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
     * Test that every sortable column is reported, not just the first, and that
     * a field declaring nothing sortable is passed over rather than ending the
     * walk: the undeclared field leads, so skipping it and stopping at it give
     * different answers.
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
     * Test that a column declared against a named index the table carries is
     * accepted, so an index introspection cannot attribute to a column is not
     * refused for being invisible.
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
     * Test that the named index is matched without regard to case, so a name
     * the engine folded to its own is not refused for the folding.
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
     * Test that a column declared against a named index the table does not
     * carry is refused, so the override cannot be used to wave the check
     * through.
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
     * Test that a column carrying a recorded exemption is accepted without the
     * catalogue being consulted for it.
     *
     * @return void
     */
    public function testAcceptsASortableColumnCarryingAnExemption(): void
    {
        $schema = $this->schema('name', unindexedReason: 'The table is bounded at a few hundred rows');

        self::assertSame([], $this->rule([])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a field declaring both a backing index and an exemption is
     * reported, since neither can be said to govern the sort.
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
     * Test that a field naming a backing index without declaring anything
     * sortable is reported, so an override with nothing to govern is not left
     * standing as though it did something.
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
     * Test that a field carrying an exemption without declaring anything
     * sortable is reported in its own terms, so an author is not sent looking
     * for an index declaration the field never made.
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
     * Test that a declaration defect is reported even against a catalogue that
     * could not be read, since it is decided from the schema alone.
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
     * Build the rule over an introspection provider reporting the given
     * catalogue, where null stands for a connection that could not be read.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Introspection\IndexDefinition>|null  $indexes
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateIndexBacking
     */
    private function rule(?array $indexes): ValidateIndexBacking
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('getIndexes')->willReturn($indexes);

        return new ValidateIndexBacking($introspector);
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
