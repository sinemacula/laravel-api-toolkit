<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationMethods;
use Tests\Fixtures\Contracts\RelationSignatures;
use Tests\Fixtures\Models\Note;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateRelationMethods validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ValidateRelationMethods::class)]
final class ValidateRelationMethodsTest extends TestCase
{
    /**
     * Test no errors for relation methods that exist on model.
     *
     * @return void
     */
    public function testNoErrorsForRelationMethodsThatExistOnModel(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
                'posts' => new CompiledFieldDefinition(
                    accessor: 'posts',
                    compute: null,
                    relation: 'posts',
                    resource: PostResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test reports relation method not existing on model.
     *
     * @return void
     */
    public function testReportsRelationMethodNotExistingOnModel(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'nonexistent' => new CompiledFieldDefinition(
                    accessor: 'nonexistent',
                    compute: null,
                    relation: 'nonexistent_relation',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('nonexistent', $errors[0]->fieldKey);
        self::assertStringContainsString('nonexistent_relation', $errors[0]->defect);
        self::assertStringContainsString(User::class, $errors[0]->defect);
    }

    /**
     * Test skips when model class is null.
     *
     * @return void
     */
    public function testSkipsWhenModelClassIsNull(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, null, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test that null field definitions are skipped without errors.
     *
     * @return void
     */
    public function testSkipsNullFieldDefinitions(): void
    {
        $reflection = new \ReflectionClass(CompiledSchema::class);
        $schema     = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('fields')->setValue($schema, ['ghost' => null]);
        $reflection->getProperty('aggregates')->setValue($schema, []);

        $rule   = new ValidateRelationMethods;
        $errors = [];

        $warnings = $this->captureWarnings(fn () => $rule->validate(UserResource::class, User::class, $schema), $errors);

        self::assertSame([], $warnings);
        self::assertSame([], $errors);
    }

    /**
     * Test that validation continues past fields without relations.
     *
     * @return void
     */
    public function testContinuesPastFieldsWithoutRelations(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'clean' => new CompiledFieldDefinition(
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
                'bad' => new CompiledFieldDefinition(
                    accessor: 'bad',
                    compute: null,
                    relation: 'missing_relation',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that every missing relation method is reported.
     *
     * @return void
     */
    public function testReportsAllMissingRelationMethods(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'first' => new CompiledFieldDefinition(
                    accessor: 'first',
                    compute: null,
                    relation: 'missing_first_relation',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
                'second' => new CompiledFieldDefinition(
                    accessor: 'second',
                    compute: null,
                    relation: 'missing_second_relation',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertCount(2, $errors);
        self::assertSame('first', $errors[0]->fieldKey);
        self::assertSame('second', $errors[1]->fieldKey);
    }

    /**
     * Test that validation continues past a field with a valid relation method.
     *
     * @return void
     */
    public function testContinuesPastFieldWithValidRelation(): void
    {
        $schema = new CompiledSchema(
            fields: [
                'organization' => new CompiledFieldDefinition(
                    accessor: 'organization',
                    compute: null,
                    relation: 'organization',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
                'bad' => new CompiledFieldDefinition(
                    accessor: 'bad',
                    compute: null,
                    relation: 'missing_relation',
                    resource: OrganizationResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('bad', $errors[0]->fieldKey);
    }

    /**
     * Test that validation continues past a valid count relation method.
     *
     * @return void
     */
    public function testContinuesPastCountDefinitionWithValidRelation(): void
    {
        $schema = new CompiledSchema(
            fields: [],
            aggregates: [
                'posts_count' => new CompiledAggregateDefinition(
                    presentKey: 'posts_count',
                    relation: 'posts',
                    function: AggregateFunction::COUNT,
                    column: null,
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
                'bad_count' => new CompiledAggregateDefinition(
                    presentKey: 'bad_count',
                    relation: 'missing_relation',
                    function: AggregateFunction::COUNT,
                    column: null,
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('bad_count', $errors[0]->fieldKey);
    }

    /**
     * Test that a count definition with a valid relation method has no error.
     *
     * @return void
     */
    public function testNoErrorForCountDefinitionWithValidRelation(): void
    {
        $schema = new CompiledSchema(
            fields: [],
            aggregates: [
                'posts_count' => new CompiledAggregateDefinition(
                    presentKey: 'posts_count',
                    relation: 'posts',
                    function: AggregateFunction::COUNT,
                    column: null,
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test reports relation method missing on model for count definition.
     *
     * @return void
     */
    public function testReportsRelationMethodMissingOnModelForCountDefinition(): void
    {
        $schema = new CompiledSchema(
            fields: [],
            aggregates: [
                'nonexistent_count' => new CompiledAggregateDefinition(
                    presentKey: 'nonexistent_count',
                    relation: 'nonexistent_relation',
                    function: AggregateFunction::COUNT,
                    column: null,
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('nonexistent_count', $errors[0]->fieldKey);
        self::assertStringContainsString('nonexistent_relation', $errors[0]->defect);
        self::assertStringContainsString(User::class, $errors[0]->defect);
    }

    /**
     * Test reports field relation method without return type hint.
     *
     * @return void
     */
    public function testReportsFieldRelationMethodWithoutReturnTypeHint(): void
    {
        $model = new class extends Model {
            // phpcs:disable Squiz.Commenting.FunctionComment.MissingReturn, SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint
            /**
             * A relation method with no return type declaration.
             */
            public function items() // @phpstan-ignore missingType.return, sineMaculaLaravel.modelBehaviour
            {
                return $this;
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.MissingReturn, SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint
        };

        $modelClass = $model::class;

        $schema = new CompiledSchema(
            fields: [
                'items' => new CompiledFieldDefinition(
                    accessor: 'items',
                    compute: null,
                    relation: 'items',
                    resource: UserResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        self::assertCount(1, $errors);
        self::assertStringContainsString('has no return type hint', $errors[0]->defect);
    }

    /**
     * Test reports field relation method with non-Relation return type.
     *
     * @return void
     */
    public function testReportsFieldRelationMethodWithNonRelationReturnType(): void
    {
        $model = new class extends Model {
            /**
             * @return string
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function wrongType(): string
            {
                return '';
            }
        };
        $modelClass = $model::class;

        $schema = new CompiledSchema(
            fields: [
                'wrong' => new CompiledFieldDefinition(
                    accessor: 'wrong',
                    compute: null,
                    relation: 'wrongType',
                    resource: UserResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        self::assertCount(1, $errors);
        self::assertStringContainsString('is not a Relation subclass', $errors[0]->defect);
        self::assertStringContainsString('string', $errors[0]->defect);
    }

    /**
     * Test passes field relation method with union type containing Relation.
     *
     * @return void
     */
    public function testPassesFieldRelationMethodWithUnionTypeContainingRelation(): void
    {
        $model = new class extends Model {
            // phpcs:disable Generic.Files.LineLength.TooLong
            /**
             * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Fixtures\Models\User, $this>|\Illuminate\Database\Eloquent\Relations\MorphMany<\Illuminate\Database\Eloquent\Model, $this>
             */
            public function items(): HasMany|MorphMany // @phpstan-ignore return.unusedType
            {
                return $this->hasMany(User::class);
            }
            // phpcs:enable Generic.Files.LineLength.TooLong
        };
        $modelClass = $model::class;

        $schema = new CompiledSchema(
            fields: [
                'items' => new CompiledFieldDefinition(
                    accessor: 'items',
                    compute: null,
                    relation: 'items',
                    resource: UserResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Test reports a field relation method whose union holds no Relation.
     *
     * @return void
     */
    public function testReportsFieldRelationMethodWithUnionTypeContainingNoRelation(): void
    {
        $model = new class extends Model {
            /**
             * @return int|string
             */
            public function items(): int|string // @phpstan-ignore return.unusedType, sineMaculaLaravel.modelBehaviour
            {
                return '';
            }
        };
        $modelClass = $model::class;

        $schema = new CompiledSchema(
            fields: [
                'items' => new CompiledFieldDefinition(
                    accessor: 'items',
                    compute: null,
                    relation: 'items',
                    resource: UserResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        self::assertCount(1, $errors);
        self::assertStringContainsString('union return type with no Relation subclass member', $errors[0]->defect);
    }

    /**
     * Test reports a field relation intersection type with no Relation.
     *
     * @return void
     */
    public function testReportsFieldRelationMethodWithIntersectionTypeContainingNoRelation(): void
    {
        $model = new class extends Model {
            /**
             * @return \ArrayAccess<int, mixed>&\Countable
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function items(): \ArrayAccess&\Countable
            {
                return new \ArrayObject;
            }
        };
        $modelClass = $model::class;

        $schema = new CompiledSchema(
            fields: [
                'items' => new CompiledFieldDefinition(
                    accessor: 'items',
                    compute: null,
                    relation: 'items',
                    resource: UserResource::class,
                    fields: null,
                    constraint: null,
                    extras: [],
                    needs: [],
                    guards: [],
                    transformers: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        self::assertCount(1, $errors);
        self::assertStringContainsString('intersection return type with no Relation subclass member', $errors[0]->defect);
    }

    /**
     * Test reports count definition relation method without return type hint.
     *
     * @return void
     */
    public function testReportsCountDefinitionRelationMethodWithoutReturnTypeHint(): void
    {
        $model = new class extends Model {
            // phpcs:disable Squiz.Commenting.FunctionComment.MissingReturn, SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint
            /**
             * A relation method with no return type declaration.
             */
            public function items() // @phpstan-ignore missingType.return, sineMaculaLaravel.modelBehaviour
            {
                return $this;
            }
            // phpcs:enable Squiz.Commenting.FunctionComment.MissingReturn, SineMaculaLaravel.TypeHints.ReturnTypeHint.MissingNativeTypeHint
        };

        $modelClass = $model::class;

        $schema = new CompiledSchema(
            fields: [],
            aggregates: [
                'items_count' => new CompiledAggregateDefinition(
                    presentKey: 'items_count',
                    relation: 'items',
                    function: AggregateFunction::COUNT,
                    column: null,
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        self::assertCount(1, $errors);
        self::assertSame('items_count', $errors[0]->fieldKey);
        self::assertStringContainsString('has no return type hint', $errors[0]->defect);
    }

    /**
     * Test reports a count relation method with a non-Relation return type.
     *
     * @return void
     */
    public function testReportsCountDefinitionRelationMethodWithNonRelationReturnType(): void
    {
        $model = new class extends Model {
            /**
             * @return string
             *
             * @phpstan-ignore sineMaculaLaravel.modelBehaviour
             */
            public function wrongType(): string
            {
                return '';
            }
        };

        $modelClass = $model::class;

        $schema = new CompiledSchema(
            fields: [],
            aggregates: [
                'wrong_count' => new CompiledAggregateDefinition(
                    presentKey: 'wrong_count',
                    relation: 'wrongType',
                    function: AggregateFunction::COUNT,
                    column: null,
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        self::assertCount(1, $errors);
        self::assertStringContainsString('is not a Relation subclass', $errors[0]->defect);
        self::assertStringContainsString('string', $errors[0]->defect);
    }

    /**
     * Test reports a missing relation method for an aggregate definition.
     *
     * @return void
     */
    public function testReportsRelationMethodMissingOnModelForAggregateDefinition(): void
    {
        $schema = new CompiledSchema(
            fields: [],
            aggregates: [
                'sum:reveiws_rating' => new CompiledAggregateDefinition(
                    presentKey: 'reveiws_rating',
                    relation: 'reveiws',
                    function: AggregateFunction::SUM,
                    column: 'rating',
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertCount(1, $errors);
        self::assertSame('reveiws_rating', $errors[0]->fieldKey);
        self::assertStringContainsString('reveiws', $errors[0]->defect);
        self::assertStringContainsString(User::class, $errors[0]->defect);
    }

    /**
     * Test that a valid aggregate relation method produces no error.
     *
     * @return void
     */
    public function testNoErrorForAggregateDefinitionWithValidRelation(): void
    {
        $schema = new CompiledSchema(
            fields: [],
            aggregates: [
                'sum:posts_id' => new CompiledAggregateDefinition(
                    presentKey: 'posts_id',
                    relation: 'posts',
                    function: AggregateFunction::SUM,
                    column: 'id',
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        self::assertSame([], $errors);
    }

    /**
     * Provide sortable relations that render more than one row, or none the
     * order can name.
     *
     * @return iterable<string, array{class-string<\Illuminate\Database\Eloquent\Model>, string}>
     */
    public static function unsortableRelationProvider(): iterable
    {
        yield 'has many' => [User::class, 'posts'];
        yield 'plain has one' => [User::class, 'profile'];
        yield 'morph to' => [Note::class, 'noteable'];
    }

    /**
     * Test that a sortable relation must render the single row its order reads.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  string  $relation
     * @return void
     */
    #[DataProvider('unsortableRelationProvider')]
    public function testReportsASortableRelationThatRendersNoSingleRow(string $model, string $relation): void
    {
        $errors = (new ValidateRelationMethods)->validate(UserResource::class, $model, $this->sortableRelation($relation));

        self::assertCount(1, $errors);
        self::assertSame($relation, $errors[0]->fieldKey);
        self::assertSame(
            sprintf('Relation "%s" is sortable, so it must be a BelongsTo, or a HasOne, MorphOne or HasOneThrough declared with latestOfMany() or ofMany()', $relation),
            $errors[0]->defect,
        );
    }

    /**
     * Test that a sortable relation the model does not define is reported as
     * missing, before its kind is read.
     *
     * @return void
     */
    public function testReportsASortableRelationTheModelDoesNotDefineAsMissing(): void
    {
        $errors = (new ValidateRelationMethods)->validate(UserResource::class, User::class, $this->sortableRelation('ghost'));

        self::assertCount(1, $errors);
        self::assertSame(sprintf('Relation method "ghost" does not exist on model "%s"', User::class), $errors[0]->defect);
    }

    /**
     * Test that a sortable belongs-to and a sortable one-of-many relation pass,
     * as do an unsortable plain has-one and a relation on a class that is no
     * model.
     *
     * @return void
     */
    public function testPassesSortableRelationsThatRenderASingleRow(): void
    {
        $rule = new ValidateRelationMethods;

        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->sortableRelation('organization')));
        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->sortableRelation('latestArticle')));
        self::assertSame([], $rule->validate(UserResource::class, User::class, $this->sortableRelation('profile', null)));
        self::assertSame([], $rule->validate(UserResource::class, RelationSignatures::class, $this->sortableRelation('profile')));
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
     * Build a schema declaring one relation, sortable when a reason is given.
     *
     * @param  string  $relation
     * @param  string|null  $reason
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function sortableRelation(string $relation, ?string $reason = 'Back-office list'): CompiledSchema
    {
        return new CompiledSchema([
            $relation => new CompiledFieldDefinition(
                accessor         : null,
                compute          : null,
                relation         : $relation,
                resource         : OrganizationResource::class,
                fields           : null,
                constraint       : null,
                extras           : [],
                needs            : [],
                guards           : [],
                transformers     : [],
                sortThroughReason: $reason,
            ),
        ]);
    }
}
