<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateNeedsColumns;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateNeedsColumns validation rule.
 *
 * The column listing is stubbed so every possible answer runs on any engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateNeedsColumns::class)]
final class ValidateNeedsColumnsTest extends TestCase
{
    /**
     * Test that a needs() column the table lacks is reported with the table.
     *
     * @return void
     */
    public function testReportsANeedsColumnTheTableLacks(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(['name', 'surname'])],
            aggregates: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertInstanceOf(SchemaValidationError::class, $errors[0]);
        self::assertSame(UserResource::class, $errors[0]->resourceClass);
        self::assertSame('full_name', $errors[0]->fieldKey);
        self::assertSame('Field declares needs() on "surname", and table "users" carries no such column', $errors[0]->defect);
    }

    /**
     * Test that every missing column is reported, past fields without needs().
     *
     * @return void
     */
    public function testReportsEveryMissingColumnOnEveryField(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'full_name' => $this->makeField(['forename', 'surname']),
                'name'      => $this->makeField(['name']),
                'avatar'    => $this->makeField([]),
                'contact'   => $this->makeField(['phone']),
            ],
            aggregates: [],
        );

        $errors = $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema);

        self::assertSame(
            [
                ['full_name', 'Field declares needs() on "forename", and table "users" carries no such column'],
                ['full_name', 'Field declares needs() on "surname", and table "users" carries no such column'],
                ['contact', 'Field declares needs() on "phone", and table "users" carries no such column'],
            ],
            array_map(static function (SchemaValidationError|UnverifiedDeclaration $error): array {
                self::assertInstanceOf(SchemaValidationError::class, $error);

                return [$error->fieldKey, $error->defect];
            }, $errors),
        );
    }

    /**
     * Test that needs() columns the table carries pass.
     *
     * @return void
     */
    public function testAcceptsNeedsColumnsTheTableCarries(): void
    {
        $schema = new CompiledSchema(
            fields: ['display_label' => $this->makeField(['name', 'email'])],
            aggregates: [],
        );

        self::assertSame([], $this->rule(['id', 'name', 'email'])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that the columns are matched exactly, so case differences count.
     *
     * @return void
     */
    public function testMatchesColumnsExactly(): void
    {
        $schema = new CompiledSchema(
            fields: ['display_label' => $this->makeField(['Name'])],
            aggregates: [],
        );

        self::assertCount(1, $this->rule(['id', 'name'])->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a schema declaring no needs() never reads the columns.
     *
     * @return void
     */
    public function testPassesOverASchemaWithoutNeeds(): void
    {
        $introspector = self::createMock(SchemaIntrospectionProvider::class);

        $introspector->expects(self::never())->method('getColumns');

        $schema = new CompiledSchema(
            fields: ['nickname' => $this->makeField([])],
            aggregates: [],
        );

        self::assertSame([], (new ValidateNeedsColumns($introspector))->validate(UserResource::class, User::class, $schema));
    }

    /**
     * Test that a resource whose mapped key is not a model is passed over.
     *
     * @param  string|null  $modelClass
     * @return void
     */
    #[TestWith([null])]
    #[TestWith([UserResource::class])]
    public function testPassesOverAKeyThatIsNotAModel(?string $modelClass): void
    {
        $introspector = self::createMock(SchemaIntrospectionProvider::class);

        $introspector->expects(self::never())->method('getColumns');

        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(['surname'])],
            aggregates: [],
        );

        self::assertSame([], (new ValidateNeedsColumns($introspector))->validate(UserResource::class, $modelClass, $schema));
    }

    /**
     * Test that an unreadable column listing reports each needs() column as
     * unverified rather than passing it.
     *
     * @return void
     */
    public function testReportsNeedsAsUnverifiedAgainstAnUnreadableColumnListing(): void
    {
        $schema = new CompiledSchema(
            fields: ['full_name' => $this->makeField(['first_name', 'surname'])],
            aggregates: [],
        );

        $results = $this->rule([])->validate(UserResource::class, User::class, $schema);

        self::assertEquals([
            new UnverifiedDeclaration(UserResource::class, 'full_name', 'table "users" carries the "first_name" column the needs() declaration names', User::class),
            new UnverifiedDeclaration(UserResource::class, 'full_name', 'table "users" carries the "surname" column the needs() declaration names', User::class),
        ], $results);
    }

    /**
     * Build the rule over the given column listing, empty meaning unreadable.
     *
     * @param  array<int, string>  $columns
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateNeedsColumns
     */
    private function rule(array $columns): ValidateNeedsColumns
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('getColumns')->willReturn($columns);

        return new ValidateNeedsColumns($introspector);
    }

    /**
     * Create a compiled field definition declaring the given needs() columns.
     *
     * @param  array<int, string>  $needs
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(array $needs): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: null,
            compute: null,
            relation: null,
            resource: null,
            fields: null,
            constraint: null,
            extras: [],
            needs: $needs,
            guards: [],
            transformers: [],
        );
    }
}
