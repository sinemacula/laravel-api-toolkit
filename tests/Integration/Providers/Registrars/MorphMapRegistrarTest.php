<?php

declare(strict_types = 1);

namespace Tests\Integration\Providers\Registrars;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Providers\Registrars\MorphMapRegistrar;
use Tests\Fixtures\Discovery\Multi\MultiModelResource;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Resources\V1\UserResource as V1UserResource;
use Tests\TestCase;

/**
 * Integration tests for the MorphMapRegistrar.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(MorphMapRegistrar::class)]
final class MorphMapRegistrarTest extends TestCase
{
    /**
     * Enable dynamic morph mapping before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('api-toolkit.resources.enable_dynamic_morph_mapping', true);
        Config::set('api-toolkit.resources.validate_schemas', false);
    }

    /**
     * Test that each resource's type is registered as its model's alias.
     *
     * @return void
     */
    public function testRegistersEachResourceTypeAsItsModelAlias(): void
    {
        $this->registerFor([
            User::class => UserResource::class,
            Post::class => PostResource::class,
        ]);

        self::assertSame(['users' => User::class, 'posts' => Post::class], Relation::morphMap());
    }

    /**
     * Test that nothing is registered when dynamic morph mapping is disabled.
     *
     * @return void
     */
    public function testRegistersNothingWhenDisabled(): void
    {
        Config::set('api-toolkit.resources.enable_dynamic_morph_mapping', false);

        $this->registerFor([User::class => UserResource::class]);

        self::assertSame([], Relation::morphMap());
    }

    /**
     * Test that nothing is registered when the resource map is not an array.
     *
     * @return void
     */
    public function testRegistersNothingWhenTheResourceMapIsNotAnArray(): void
    {
        Config::set('api-toolkit.resources.resource_map', UserResource::class);

        (new MorphMapRegistrar)->register();

        self::assertSame([], Relation::morphMap());
    }

    /**
     * Test that registering the derived aliases never requires a morph map, so
     * a model without a resource keeps morphing to its class name.
     *
     * @return void
     */
    public function testNeverRequiresAMorphMap(): void
    {
        $this->registerFor([User::class => UserResource::class]);

        self::assertFalse(Relation::requiresMorphMap());
        self::assertSame('users', (new User)->getMorphClass());
        self::assertSame(Organization::class, (new Organization)->getMorphClass());
    }

    /**
     * Test that an empty resource map maps nothing and requires nothing.
     *
     * @return void
     */
    public function testEmptyResourceMapMapsNothing(): void
    {
        $this->registerFor([]);

        self::assertSame([], Relation::morphMap());
        self::assertFalse(Relation::requiresMorphMap());
    }

    /**
     * Test that an alias the application registered first is kept.
     *
     * @return void
     */
    public function testKeepsAnAliasTheApplicationRegisteredFirst(): void
    {
        Relation::morphMap(['users' => Organization::class]);

        $this->registerFor([
            User::class => UserResource::class,
            Post::class => PostResource::class,
        ]);

        self::assertSame(['posts' => Post::class, 'users' => Organization::class], Relation::morphMap());
    }

    /**
     * Test that a model the application already mapped is not re-aliased.
     *
     * @return void
     */
    public function testDoesNotReAliasAModelTheApplicationMapped(): void
    {
        Relation::morphMap(['member' => User::class]);

        $this->registerFor([
            User::class => UserResource::class,
            Post::class => PostResource::class,
        ]);

        self::assertSame(['posts' => Post::class, 'member' => User::class], Relation::morphMap());
    }

    /**
     * Test that a morph map the application registers later merges with the
     * derived aliases and wins on a shared alias.
     *
     * @return void
     */
    public function testALaterApplicationMorphMapMergesAndWins(): void
    {
        $this->registerFor([
            User::class => UserResource::class,
            Post::class => PostResource::class,
        ]);

        Relation::morphMap(['users' => Organization::class, 'tags' => Tag::class]);

        self::assertSame([
            'users' => Organization::class,
            'tags'  => Tag::class,
            'posts' => Post::class,
        ], Relation::morphMap());
    }

    /**
     * Test that a resource bound to several models is aliased for none of them,
     * silently.
     *
     * @return void
     */
    public function testWithholdsTheTypeOfAResourceBoundToSeveralModelsSilently(): void
    {
        Log::shouldReceive('warning')->never();

        $this->registerFor([
            User::class         => MultiModelResource::class,
            Post::class         => MultiModelResource::class,
            Organization::class => OrganizationResource::class,
        ]);

        self::assertSame(['organizations' => Organization::class], Relation::morphMap());
    }

    /**
     * Test that a type shared by distinct resources is aliased for none of
     * their models, with a warning.
     *
     * @return void
     */
    public function testWithholdsATypeSharedByDistinctResourcesWithAWarning(): void
    {
        Log::shouldReceive('warning')->once()->with($this->sharedTypeMessage());

        $this->registerFor([
            User::class         => UserResource::class,
            Post::class         => V1UserResource::class,
            Organization::class => OrganizationResource::class,
        ]);

        self::assertSame(['organizations' => Organization::class], Relation::morphMap());
    }

    /**
     * Test that a type shared by distinct resources is fatal when schema
     * validation is enabled.
     *
     * @return void
     */
    public function testATypeSharedByDistinctResourcesIsFatalWhenValidatingSchemas(): void
    {
        Config::set('api-toolkit.resources.validate_schemas', true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($this->sharedTypeMessage());

        $this->registerFor([
            User::class => UserResource::class,
            Post::class => V1UserResource::class,
        ]);
    }

    /**
     * Test that a type shared by distinct resources is not reported once the
     * application has registered the alias itself.
     *
     * @return void
     */
    public function testDoesNotReportASharedTypeTheApplicationAliased(): void
    {
        Config::set('api-toolkit.resources.validate_schemas', true);
        Log::shouldReceive('warning')->never();

        Relation::morphMap(['users' => User::class]);

        $this->registerFor([
            User::class         => UserResource::class,
            Post::class         => V1UserResource::class,
            Organization::class => OrganizationResource::class,
        ]);

        self::assertSame(['organizations' => Organization::class, 'users' => User::class], Relation::morphMap());
    }

    /**
     * Test that a numeric resource type is registered as its model's alias.
     *
     * @return void
     */
    public function testRegistersANumericResourceType(): void
    {
        $resource = new class {
            /**
             * Get the resource type.
             *
             * @return string
             */
            public static function getResourceType(): string
            {
                return '123';
            }
        };

        $this->registerFor([User::class => $resource::class]);

        self::assertSame(User::class, Relation::getMorphedModel('123'));
    }

    /**
     * Test that a resource without a resource type is skipped.
     *
     * @return void
     */
    public function testSkipsAResourceWithoutAResourceType(): void
    {
        $this->registerFor([
            User::class => \stdClass::class,
            Post::class => PostResource::class,
        ]);

        self::assertSame(['posts' => Post::class], Relation::morphMap());
    }

    /**
     * Register the morph aliases for the given resource map.
     *
     * @param  array<class-string, class-string>  $map
     * @return void
     */
    private function registerFor(array $map): void
    {
        Config::set('api-toolkit.resources.resource_map', $map);

        (new MorphMapRegistrar)->register();
    }

    /**
     * The warning reported for the users type shared by the root and v1 user
     * resources.
     *
     * @return string
     */
    private function sharedTypeMessage(): string
    {
        return 'Dynamic morph mapping found multiple resources with the type [users]: ['
            . UserResource::class . '], [' . V1UserResource::class . ']. '
            . 'No morph alias is registered for it; give each resource a distinct RESOURCE_TYPE, '
            . 'or register the alias in the application morph map from the register method of a service provider.';
    }
}
