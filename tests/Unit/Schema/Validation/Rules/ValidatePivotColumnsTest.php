<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatePivotColumns;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PlainJsonResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidatePivotColumns validation rule.
 *
 * The relation resolves from the model and the column listing is stubbed, so
 * every answer runs on any engine.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidatePivotColumns::class)]
final class ValidatePivotColumnsTest extends TestCase
{
    /**
     * Clear the compiled schemas.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Test that pivot columns the pivot table carries pass.
     *
     * @return void
     */
    public function testAcceptsColumnsThePivotTableCarries(): void
    {
        self::assertSame([], $this->validate($this->makeField(['role', 'tagged_at']), ['post_id', 'tag_id', 'role', 'tagged_at']));
    }

    /**
     * Test that each column the pivot table lacks is reported with the table.
     *
     * @return void
     */
    public function testReportsEachColumnThePivotTableLacks(): void
    {
        self::assertSame([
            'Field declares pivot column "rank", and pivot table "post_tag" carries no such column',
            'Field declares pivot column "since", and pivot table "post_tag" carries no such column',
        ], self::defects($this->validate($this->makeField(['role', 'rank', 'since']), ['post_id', 'tag_id', 'role'])));
    }

    /**
     * Test that a field rendering no child resource is refused before the
     * relation is read.
     *
     * @param  string|null  $relation
     * @param  string|null  $resource
     * @param  string|null  $accessor
     * @return void
     */
    #[TestWith([null, TagResource::class, null])]
    #[TestWith(['tags', null, 'name'])]
    #[TestWith(['tags', TagResource::class, 'name'])]
    #[TestWith(['tags', PlainJsonResource::class, null])]
    public function testRefusesAFieldRenderingNoChildResource(?string $relation, ?string $resource, ?string $accessor): void
    {
        $introspector = self::createMock(SchemaIntrospectionProvider::class);

        $introspector->expects(self::never())->method('resolveRelation');

        $results = (new ValidatePivotColumns($introspector))->validate(PostResource::class, Post::class, new CompiledSchema([
            'tags' => $this->makeField(['role'], $relation, $resource, $accessor),
        ], []));

        self::assertSame(['Field declares pivot columns but renders no child resource'], self::defects($results));
    }

    /**
     * Test that a child resource declaring a pivot field is refused, since the
     * pivot columns would overwrite it.
     *
     * @return void
     */
    public function testRefusesAChildResourceDeclaringAPivotField(): void
    {
        $child = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'pivoting_tags';

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'), Field::scalar('pivot'));
            }
        };

        $results = $this->validate($this->makeField(['role'], resource: $child::class), ['role']);

        self::assertSame([sprintf('Field declares pivot columns, and child resource %s declares a "pivot" field they would overwrite', $child::class)], self::defects($results));
    }

    /**
     * Test that a relation which is not many-to-many is refused by its class.
     *
     * @return void
     */
    public function testRefusesARelationThatIsNotManyToMany(): void
    {
        $results = $this->rule(['role'])->validate(UserResource::class, User::class, new CompiledSchema([
            'posts' => $this->makeField(['role'], 'posts', PostResource::class),
        ], []));

        self::assertSame(['Relation "posts" declares pivot columns but resolves to HasMany; pivot() needs a BelongsToMany or MorphToMany'], self::defects($results));
    }

    /**
     * Test that an unreadable column listing reports each pivot column as
     * unverified, read from the relation's own pivot table.
     *
     * @return void
     */
    public function testReportsEachColumnAsUnverifiedAgainstAnUnreadableListing(): void
    {
        $tables       = [];
        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('resolveRelation')->willReturnCallback(self::relationOf(...));
        $introspector->method('getColumns')->willReturnCallback(static function (Model $model) use (&$tables): array {
            $tables[] = $model instanceof Pivot ? $model->getTable() : null;

            return [];
        });

        $results = (new ValidatePivotColumns($introspector))->validate(PostResource::class, Post::class, new CompiledSchema([
            'tags' => $this->makeField(['role', 'tagged_at']),
        ], []));

        self::assertEquals([
            new UnverifiedDeclaration(PostResource::class, 'tags', 'pivot table "post_tag" carries the "role" column the pivot() declaration names', Post::class),
            new UnverifiedDeclaration(PostResource::class, 'tags', 'pivot table "post_tag" carries the "tagged_at" column the pivot() declaration names', Post::class),
        ], $results);
        self::assertSame(['post_tag'], $tables);
    }

    /**
     * Test that a relation the model does not resolve, a key that is not a
     * model and a field declaring no pivot leave the columns unread.
     *
     * @param  string  $relation
     * @param  string|null  $modelClass
     * @param  array<int, string>  $pivot
     * @return void
     */
    #[TestWith(['missing', Post::class, ['role']])]
    #[TestWith(['tags', null, ['role']])]
    #[TestWith(['tags', PostResource::class, ['role']])]
    #[TestWith(['tags', Post::class, []])]
    public function testLeavesTheColumnsUnreadWhenNothingCanBeChecked(string $relation, ?string $modelClass, array $pivot): void
    {
        $introspector = self::createMock(SchemaIntrospectionProvider::class);

        $introspector->method('resolveRelation')->willReturnCallback(self::relationOf(...));
        $introspector->expects(self::never())->method('getColumns');

        $results = (new ValidatePivotColumns($introspector))->validate(PostResource::class, $modelClass, new CompiledSchema([
            'tags' => $this->makeField($pivot, $relation),
        ], []));

        self::assertSame([], $results);
    }

    /**
     * Resolve the named relation method on the model, or null without one.
     *
     * @param  string  $key
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>|null
     */
    private static function relationOf(string $key, Model $model): mixed
    {
        return method_exists($model, $key) ? $model->{$key}() : null; // @phpstan-ignore method.dynamicName
    }

    /**
     * Return the defect of each result, asserting each is an error.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>  $results
     * @return list<string>
     */
    private static function defects(array $results): array
    {
        return array_values(array_map(static function (SchemaValidationError|UnverifiedDeclaration $result): string {
            self::assertInstanceOf(SchemaValidationError::class, $result);

            return $result->defect;
        }, $results));
    }

    /**
     * Validate the post resource's tags field, after a field declaring no
     * pivot, against the given pivot columns.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition  $field
     * @param  array<int, string>  $columns
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError|\SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>
     */
    private function validate(CompiledFieldDefinition $field, array $columns): array
    {
        $schema = new CompiledSchema(['title' => $this->makeField([], null, null), 'tags' => $field], []);

        return $this->rule($columns)->validate(PostResource::class, Post::class, $schema);
    }

    /**
     * Build the rule over the model's relations and the given column listing.
     *
     * @param  array<int, string>  $columns
     * @return \SineMacula\ApiToolkit\Schema\Validation\Rules\ValidatePivotColumns
     */
    private function rule(array $columns): ValidatePivotColumns
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);

        $introspector->method('resolveRelation')->willReturnCallback(self::relationOf(...));
        $introspector->method('getColumns')->willReturn($columns);

        return new ValidatePivotColumns($introspector);
    }

    /**
     * Create a compiled relation field declaring the given pivot columns.
     *
     * @param  array<int, string>  $pivot
     * @param  string|null  $relation
     * @param  string|null  $resource
     * @param  string|null  $accessor
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function makeField(array $pivot, ?string $relation = 'tags', ?string $resource = TagResource::class, ?string $accessor = null): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor: $accessor,
            compute: null,
            relation: $relation,
            resource: $resource,
            fields: null,
            constraint: null,
            extras: [],
            needs: [],
            guards: [],
            transformers: [],
            pivot: $pivot,
        );
    }
}
