<?php

namespace Tests\Unit\Services\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledCountDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Http\Resources\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Services\Validation\Rules\ValidateRelationMethods;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;

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
class ValidateRelationMethodsTest extends TestCase
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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        static::assertSame([], $errors);
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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        static::assertCount(1, $errors);
        static::assertSame('nonexistent', $errors[0]->fieldKey);
        static::assertStringContainsString('nonexistent_relation', $errors[0]->defect);
        static::assertStringContainsString(User::class, $errors[0]->defect);
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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, null, $schema);

        static::assertSame([], $errors);
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
        $reflection->getProperty('counts')->setValue($schema, []);

        $rule   = new ValidateRelationMethods;
        $errors = [];

        $warnings = $this->captureWarnings(fn () => $rule->validate(UserResource::class, User::class, $schema), $errors);

        static::assertSame([], $warnings);
        static::assertSame([], $errors);
    }

    /**
     * Test that validation continues past fields without relations and still
     * reports later defects.
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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        static::assertCount(1, $errors);
        static::assertSame('bad', $errors[0]->fieldKey);
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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        static::assertCount(2, $errors);
        static::assertSame('first', $errors[0]->fieldKey);
        static::assertSame('second', $errors[1]->fieldKey);
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
            counts: [
                'nonexistent_count' => new CompiledCountDefinition(
                    presentKey: 'nonexistent_count',
                    relation: 'nonexistent_relation',
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, User::class, $schema);

        static::assertCount(1, $errors);
        static::assertSame('nonexistent_count', $errors[0]->fieldKey);
        static::assertStringContainsString('nonexistent_relation', $errors[0]->defect);
        static::assertStringContainsString(User::class, $errors[0]->defect);
    }

    /**
     * Test reports field relation method without return type hint.
     *
     * @return void
     */
    public function testReportsFieldRelationMethodWithoutReturnTypeHint(): void
    {
        $model = new class extends Model {
            /** @return mixed */
            public function items()
            {
                return null;
            }
        };

        $modelClass = get_class($model);

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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        static::assertCount(1, $errors);
        static::assertStringContainsString('has no return type hint', $errors[0]->defect);
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
             */
            public function wrongType(): string
            {
                return '';
            }
        };
        $modelClass = get_class($model);

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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        static::assertCount(1, $errors);
        static::assertStringContainsString('is not a Relation subclass', $errors[0]->defect);
        static::assertStringContainsString('string', $errors[0]->defect);
    }

    /**
     * Test passes field relation method with union type containing Relation.
     *
     * @return void
     */
    public function testPassesFieldRelationMethodWithUnionTypeContainingRelation(): void
    {
        $model = new class extends Model {
            /**
             * @return \Illuminate\Database\Eloquent\Relations\HasMany|\Illuminate\Database\Eloquent\Relations\MorphMany
             */
            public function items(): HasMany|MorphMany
            {
                return $this->hasMany(self::class);
            }
        };
        $modelClass = get_class($model);

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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        static::assertSame([], $errors);
    }

    /**
     * Test reports field relation method with union type containing no
     * Relation.
     *
     * @return void
     */
    public function testReportsFieldRelationMethodWithUnionTypeContainingNoRelation(): void
    {
        $model = new class extends Model {
            /**
             * @return string|int
             */
            public function items(): string|int
            {
                return '';
            }
        };
        $modelClass = get_class($model);

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
                    guards: [],
                    transformers: [],
                ),
            ],
            counts: [],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        static::assertCount(1, $errors);
        static::assertStringContainsString('union return type with no Relation subclass member', $errors[0]->defect);
    }

    /**
     * Test reports count definition relation method without return type hint.
     *
     * @return void
     */
    public function testReportsCountDefinitionRelationMethodWithoutReturnTypeHint(): void
    {
        $model = new class extends Model {
            /** @return mixed */
            public function items()
            {
                return null;
            }
        };

        $modelClass = get_class($model);

        $schema = new CompiledSchema(
            fields: [],
            counts: [
                'items_count' => new CompiledCountDefinition(
                    presentKey: 'items_count',
                    relation: 'items',
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        static::assertCount(1, $errors);
        static::assertSame('items_count', $errors[0]->fieldKey);
        static::assertStringContainsString('has no return type hint', $errors[0]->defect);
    }

    /**
     * Test reports count definition relation method with non-Relation return
     * type.
     *
     * @return void
     */
    public function testReportsCountDefinitionRelationMethodWithNonRelationReturnType(): void
    {
        $model = new class extends Model {
            /**
             * @return string
             */
            public function wrongType(): string
            {
                return '';
            }
        };

        $modelClass = get_class($model);

        $schema = new CompiledSchema(
            fields: [],
            counts: [
                'wrong_count' => new CompiledCountDefinition(
                    presentKey: 'wrong_count',
                    relation: 'wrongType',
                    constraint: null,
                    isDefault: false,
                    guards: [],
                ),
            ],
        );

        $rule   = new ValidateRelationMethods;
        $errors = $rule->validate(UserResource::class, $modelClass, $schema);

        static::assertCount(1, $errors);
        static::assertStringContainsString('is not a Relation subclass', $errors[0]->defect);
        static::assertStringContainsString('string', $errors[0]->defect);
    }

    /**
     * Run the given callback while capturing any raised PHP warnings.
     *
     * @param  callable(): array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>  $callback
     * @param  array<int, \SineMacula\ApiToolkit\Services\Validation\SchemaValidationError>  $errors
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
}
