<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation as EloquentRelation;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\RelationLimit;
use Tests\Fixtures\Models\Comment;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Profile;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\PostResource;
use Tests\TestCase;

/**
 * Tests for the relation limit resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RelationLimit::class)]
final class RelationLimitTest extends TestCase
{
    /**
     * Test that a declared limit wins over the default.
     *
     * @return void
     */
    public function testDeclaredLimitWinsOverTheDefault(): void
    {
        Config::set('api-toolkit.resources.relation_limit', 25);

        self::assertSame(5, RelationLimit::for(self::field(limit: 5)));
    }

    /**
     * Test that a relation declaring no limit takes the default.
     *
     * @return void
     */
    public function testUndeclaredLimitTakesTheDefault(): void
    {
        Config::set('api-toolkit.resources.relation_limit', '12');

        self::assertSame(12, RelationLimit::for(self::field()));
    }

    /**
     * Test that the shipped default bounds a relation declaring no limit.
     *
     * @return void
     */
    public function testShippedDefaultIsTwentyFive(): void
    {
        self::assertSame(25, RelationLimit::for(self::field()));
    }

    /**
     * Provide default values that disable the relation limit.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function disabledDefaultProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'null' => [null];
        yield 'negative' => [-4];
        yield 'not numeric' => ['many'];
    }

    /**
     * Test that a disabled default leaves an undeclared relation unbounded.
     *
     * @param  mixed  $default
     * @return void
     */
    #[DataProvider('disabledDefaultProvider')]
    public function testDisabledDefaultLeavesTheRelationUnbounded(mixed $default): void
    {
        Config::set('api-toolkit.resources.relation_limit', $default);

        self::assertSame(0, RelationLimit::for(self::field()));
        self::assertSame(3, RelationLimit::for(self::field(limit: 3)));
    }

    /**
     * Test that a relation with no child resource is never bounded.
     *
     * @return void
     */
    public function testRelationWithoutAChildResourceIsUnbounded(): void
    {
        self::assertSame(0, RelationLimit::for(self::field(limit: 5, resource: null)));
        self::assertSame(0, RelationLimit::for(self::field(resource: null)));
    }

    /**
     * Test that an accessor relation is never bounded.
     *
     * @return void
     */
    public function testAccessorRelationIsUnbounded(): void
    {
        self::assertSame(0, RelationLimit::for(self::field(limit: 5, accessor: 'title')));
        self::assertSame(0, RelationLimit::for(self::field(accessor: 'title')));
    }

    /**
     * Test that a field that is not a relation is never bounded.
     *
     * @return void
     */
    public function testNonRelationFieldIsUnbounded(): void
    {
        self::assertSame(0, RelationLimit::for(self::field(limit: 5, relation: null)));
    }

    /**
     * Provide each relation kind with whether it is bounded per parent.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function relationProvider(): iterable
    {
        yield 'has many' => ['hasMany', true];
        yield 'morph many' => ['morphMany', true];
        yield 'belongs to many' => ['belongsToMany', true];
        yield 'morph to many' => ['morphToMany', true];
        yield 'has many through' => ['hasManyThrough', true];
        yield 'has one' => ['hasOne', false];
        yield 'morph one' => ['morphOne', false];
        yield 'belongs to' => ['belongsTo', false];
        yield 'morph to' => ['morphTo', false];
        yield 'has one through' => ['hasOneThrough', false];
    }

    /**
     * Test which relation classes bound an eager load per parent.
     *
     * @param  string  $name
     * @param  bool  $applies
     * @return void
     */
    #[DataProvider('relationProvider')]
    public function testAppliesToOnlyPerParentToManyRelations(string $name, bool $applies): void
    {
        $relation = self::relations()[$name]();

        self::assertInstanceOf(EloquentRelation::class, $relation);
        self::assertSame($applies, RelationLimit::appliesTo($relation));
    }

    /**
     * Build a compiled field with the given relation attributes.
     *
     * @param  int|null  $limit
     * @param  string|null  $resource
     * @param  string|null  $accessor
     * @param  string|null  $relation
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private static function field(?int $limit = null, ?string $resource = PostResource::class, ?string $accessor = null, ?string $relation = 'posts'): CompiledFieldDefinition
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
            limit: $limit,
        );
    }

    /**
     * Build a factory for one relation of each kind.
     *
     * @return array<string, \Closure(): mixed>
     */
    private static function relations(): array
    {
        $model = new #[Table('users')] class extends Model {
            /**
             * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\Tests\Fixtures\Models\Comment, $this>
             */
            public function notes(): MorphMany
            {
                return $this->morphMany(Comment::class, 'commentable');
            }

            /**
             * @return \Illuminate\Database\Eloquent\Relations\MorphOne<\Tests\Fixtures\Models\Comment, $this>
             */
            public function note(): MorphOne
            {
                return $this->morphOne(Comment::class, 'commentable');
            }

            /**
             * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
             */
            public function subject(): MorphTo
            {
                return $this->morphTo();
            }

            /**
             * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough<\Tests\Fixtures\Models\Profile, \Tests\Fixtures\Models\User, $this>
             */
            public function firstProfile(): HasOneThrough
            {
                return $this->hasOneThrough(Profile::class, User::class, 'organization_id');
            }
        };

        return [
            'hasMany'        => static fn (): EloquentRelation => (new User)->posts(),
            'morphMany'      => static fn (): EloquentRelation => $model->notes(),
            'belongsToMany'  => static fn (): EloquentRelation => (new Post)->tags(),
            'morphToMany'    => static fn (): EloquentRelation => (new Tag)->articles(),
            'hasManyThrough' => static fn (): EloquentRelation => (new Organization)->posts(),
            'hasOne'         => static fn (): EloquentRelation => (new User)->profile(),
            'morphOne'       => static fn (): EloquentRelation => $model->note(),
            'belongsTo'      => static fn (): EloquentRelation => (new User)->organization(),
            'morphTo'        => static fn (): EloquentRelation => $model->subject(),
            'hasOneThrough'  => static fn (): EloquentRelation => $model->firstProfile(),
        ];
    }
}
