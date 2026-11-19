<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRowPerimeterDefaults;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use Tests\Fixtures\Concerns\LoadsPostsByDefault;
use Tests\Fixtures\Models\DefaultLoadingUser;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\AliasedScalarUserResource;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\OwnPostResource;
use Tests\Fixtures\Resources\PostOwnerResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateRowPerimeterDefaults validation rule.
 *
 * Each case loads relations by default from a model whose relations reach the
 * posts, which the mapped resource restricts to the caller's own.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateRowPerimeterDefaults::class)]
final class ValidateRowPerimeterDefaultsTest extends TestCase
{
    /** @var string The defect of a default reaching the restricted posts */
    private const string REACHES_POSTS = 'reaches "posts", whose rows ' . OwnPostResource::class . ' restricts';

    /**
     * Map the posts to a resource restricting them.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.resources.resource_map', [Post::class => OwnPostResource::class, User::class => AliasedScalarUserResource::class]);
    }

    /**
     * Test that a default load reaching a restricting resource is refused,
     * naming the model and the relation.
     *
     * @return void
     */
    public function testDefaultLoadReachingARestrictingResourceIsRefused(): void
    {
        $model = new DefaultLoadingUser;

        self::assertEquals([self::refusal($model, 'posts', 'with', self::REACHES_POSTS)], $this->validate($model));
    }

    /**
     * Test that a dotted default load is refused at the restricting relation it
     * reaches, beside a legal one.
     *
     * @return void
     */
    public function testDottedDefaultLoadIsRefusedWhereItReachesTheRestriction(): void
    {
        $model = new class extends DefaultLoadingUser {
            /** @var array<int, string> */
            protected $with = ['organization', 'author.posts']; // @phpstan-ignore property.phpDocType
        };

        self::assertEquals([self::refusal($model, 'author.posts', 'with', self::REACHES_POSTS)], $this->validate($model));
    }

    /**
     * Test that a default keyed by its relation, with nested loads, is refused
     * at that relation.
     *
     * @return void
     */
    public function testDefaultKeyedByItsRelationIsRefused(): void
    {
        $model = new class extends DefaultLoadingUser {
            /** @var array<string, array<int, string>> */
            protected $with = ['posts' => ['tags']]; // @phpstan-ignore property.phpDocType
        };

        self::assertEquals([self::refusal($model, 'posts', 'with', self::REACHES_POSTS)], $this->validate($model));
    }

    /**
     * Test that a default count over a restricting relation is refused.
     *
     * @return void
     */
    public function testDefaultCountOverARestrictingRelationIsRefused(): void
    {
        $model = new class extends DefaultLoadingUser {
            /** @var array<int, string> */
            protected $with = []; // @phpstan-ignore property.phpDocType

            /** @var array<int, string> */
            protected $withCount = ['posts as total']; // @phpstan-ignore property.phpDocType
        };

        self::assertEquals([self::refusal($model, 'posts as total', 'withCount', self::REACHES_POSTS)], $this->validate($model));
    }

    /**
     * Test that a default a trait adds as the model is built is seen.
     *
     * @return void
     */
    public function testDefaultATraitAddsIsSeen(): void
    {
        $model = new class extends DefaultLoadingUser {
            use LoadsPostsByDefault;

            /** @var array<int, string> */
            protected $with = []; // @phpstan-ignore property.phpDocType
        };

        self::assertEquals([self::refusal($model, 'posts', 'with', self::REACHES_POSTS)], $this->validate($model));
    }

    /**
     * Test that a relation method without a relation return type is resolved by
     * calling it.
     *
     * @return void
     */
    public function testUntypedRelationMethodIsResolvedByCallingIt(): void
    {
        $model = new class extends DefaultLoadingUser {
            /** @var array<int, string> */
            protected $with = ['drafts']; // @phpstan-ignore property.phpDocType

            /**
             * Return the user's posts, without a relation return type.
             *
             * @return mixed
             */
            public function drafts(): mixed
            {
                return $this->hasMany(Post::class, 'user_id');
            }
        };

        self::assertEquals([self::refusal($model, 'drafts', 'with', 'reaches "drafts", whose rows ' . OwnPostResource::class . ' restricts')], $this->validate($model));
    }

    /**
     * Test that a default load whose target cannot be resolved is refused.
     *
     * @return void
     */
    public function testDefaultLoadWithAnUnresolvableTargetIsRefused(): void
    {
        $model = new class extends DefaultLoadingUser {
            /** @var array<int, string> */
            protected $with = ['ghost', 'subject', 'label']; // @phpstan-ignore property.phpDocType
        };

        self::assertEquals([
            self::refusal($model, 'ghost', 'with', 'cannot be resolved to the resource governing "ghost"'),
            self::refusal($model, 'subject', 'with', 'cannot be resolved to the resource governing "subject"'),
            self::refusal($model, 'label', 'with', 'cannot be resolved to the resource governing "label"'),
        ], $this->validate($model));
    }

    /**
     * Test that a default load reaching only unrestricted resources is legal.
     *
     * @return void
     */
    public function testDefaultLoadReachingOnlyUnrestrictedResourcesIsLegal(): void
    {
        $model = new class extends DefaultLoadingUser {
            /** @var array<int, string> */
            protected $with = ['organization', 'author:id,name']; // @phpstan-ignore property.phpDocType
        };

        self::assertSame([], $this->validate($model));
    }

    /**
     * Test that the resource each level declares over a default relation
     * governs it rather than the resource mapped to its model.
     *
     * @return void
     */
    public function testDeclaredResourceGovernsADefaultOverTheMappedOne(): void
    {
        $model = new DefaultLoadingUser;

        $nested = new class extends DefaultLoadingUser {
            /** @var array<int, string> */
            protected $with = ['author.posts']; // @phpstan-ignore property.phpDocType
        };

        $counted = new class extends DefaultLoadingUser {
            /** @var array<int, string> */
            protected $with = []; // @phpstan-ignore property.phpDocType

            /** @var array<int, string> */
            protected $withCount = ['posts']; // @phpstan-ignore property.phpDocType
        };

        self::assertSame([], $this->validate($model, UserResource::class));
        self::assertSame([], $this->validate($nested, ArticleResource::class));

        Config::set('api-toolkit.resources.resource_map', [Post::class => PostResource::class]);

        self::assertEquals([self::refusal($model, 'posts', 'with', self::REACHES_POSTS, PostOwnerResource::class)], $this->validate($model, PostOwnerResource::class));
        self::assertEquals([self::refusal($counted, 'posts', 'withCount', self::REACHES_POSTS, PostOwnerResource::class)], $this->validate($counted, PostOwnerResource::class));
    }

    /**
     * Test that an unresolvable default is legal while no mapped resource
     * restricts, since only a mapped resource governs it as it loads.
     *
     * @return void
     */
    public function testUnresolvableDefaultIsLegalWhileNoMappedResourceRestricts(): void
    {
        Config::set('api-toolkit.resources.resource_map', [Post::class => PostResource::class]);

        $model = new class extends DefaultLoadingUser {
            /** @var array<int, string> */
            protected $with = ['ghost', 'subject']; // @phpstan-ignore property.phpDocType
        };

        self::assertSame([], $this->validate($model));
    }

    /**
     * Test that a default entry naming no relation path is skipped.
     *
     * @return void
     */
    public function testDefaultEntryNamingNoRelationPathIsSkipped(): void
    {
        $model = new class extends DefaultLoadingUser {
            /** @var array<int, mixed> */
            protected $with = [['posts'], 'posts']; // @phpstan-ignore property.phpDocType
        };

        self::assertEquals([self::refusal($model, 'posts', 'with', self::REACHES_POSTS)], $this->validate($model));
    }

    /**
     * Test that a resource serving no model is not checked.
     *
     * @return void
     */
    public function testResourceServingNoModelIsNotChecked(): void
    {
        $rule = new ValidateRowPerimeterDefaults;

        self::assertSame([], $rule->validate(PostOwnerResource::class, null, new CompiledSchema([])));
        self::assertSame([], $rule->validate(PostOwnerResource::class, \stdClass::class, new CompiledSchema([])));
    }

    /**
     * Validate the model as served by the given resource.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $resource
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    private function validate(Model $model, string $resource = AliasedScalarUserResource::class): array
    {
        return (new ValidateRowPerimeterDefaults)->validate($resource, $model::class, new CompiledSchema([]));
    }

    /**
     * Build the error refusing the model's default load.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $path
     * @param  string  $property
     * @param  string  $defect
     * @param  string  $resource
     * @return \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError
     */
    private static function refusal(Model $model, string $path, string $property, string $defect, string $resource = AliasedScalarUserResource::class): SchemaValidationError
    {
        return new SchemaValidationError(
            $resource,
            $path,
            sprintf('Model "%s" loads "%s" by default through $%s, which %s; load it explicitly instead', $model::class, $path, $property, $defect),
        );
    }
}
