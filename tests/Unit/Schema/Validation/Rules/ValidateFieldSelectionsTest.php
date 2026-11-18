<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation\Rules;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateFieldSelections;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PlainJsonResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ValidateFieldSelections validation rule.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateFieldSelections::class)]
final class ValidateFieldSelectionsTest extends TestCase
{
    /**
     * Catalogue the fixture resources so their relation children are
     * selectable.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, PostResource::class, TagResource::class, OrganizationResource::class]);
    }

    /**
     * Test that declared defaults and catalogued children raise nothing.
     *
     * @return void
     */
    public function testDeclaredSelectionsRaiseNothing(): void
    {
        self::assertSame([], $this->validate(UserResource::class));
    }

    /**
     * Test that a default field the resource does not declare is reported.
     *
     * @return void
     */
    public function testUndeclaredDefaultFieldIsReported(): void
    {
        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'defaulting_users';

            /** @var array<int, string> */
            protected static array $default = ['id', 'emial', '_type'];

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'));
            }
        };

        $this->assertErrors([
            [$resource::class, 'emial', sprintf('Default field "emial" is not declared on %s', $resource::class)],
        ], $this->validate($resource::class));
    }

    /**
     * Test that a fixed field the resource does not declare is reported,
     * whether configured or the resource's own, while `_type` is accepted.
     *
     * @return void
     */
    public function testUndeclaredFixedFieldIsReported(): void
    {
        Config::set('api-toolkit.resources.fixed_fields', ['uuid', '_type']);

        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'fixing_users';

            /** @var array<int, string> */
            protected static array $fixed = ['id', 'stauts'];

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'));
            }
        };

        $this->assertErrors([
            [$resource::class, 'uuid', sprintf('Fixed field "uuid" is not declared on %s', $resource::class)],
            [$resource::class, 'stauts', sprintf('Fixed field "stauts" is not declared on %s', $resource::class)],
        ], $this->validate($resource::class));
    }

    /**
     * Test that the all token in the defaults is reported.
     *
     * @return void
     */
    public function testAllTokenInTheDefaultsIsReported(): void
    {
        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'all_defaulting_users';

            /** @var array<int, string> */
            protected static array $default = [':all'];

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'));
            }
        };

        $this->assertErrors([
            [$resource::class, ':all', sprintf('Default fields cannot use :all; list the fields of %s', $resource::class)],
        ], $this->validate($resource::class));
    }

    /**
     * Test that a pinned field the child does not declare, and the all token in
     * a pin, are reported against the relation, while the child's payload names
     * are accepted.
     *
     * @return void
     */
    public function testUndeclaredPinnedFieldsAreReported(): void
    {
        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'pinning_users';

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('posts', PostResource::class)->fields(['title', 'aggregates', '_type', 'nope', ':all']),
                );
            }
        };

        $this->assertErrors([
            [$resource::class, 'posts', sprintf('Pinned field "nope" is not declared on %s', PostResource::class)],
            [$resource::class, 'posts', sprintf('Pinned fields cannot use :all; list the fields of %s', PostResource::class)],
        ], $this->validate($resource::class));
    }

    /**
     * Test that a relation child that is not an API resource is skipped.
     *
     * @return void
     */
    public function testRelationChildThatIsNotAnApiResourceIsSkipped(): void
    {
        $resource = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'plain_child_users';

            /**
             * Return the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('organization', PlainJsonResource::class)->fields(['anything']),
                );
            }
        };

        self::assertSame([], $this->validate($resource::class));
    }

    /**
     * Test that a relation child outside the catalogue is reported, since its
     * type could never be selected.
     *
     * @return void
     */
    public function testUncataloguedRelationChildIsReported(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, PostResource::class]);

        $this->assertErrors([
            [
                PostResource::class,
                'tags',
                sprintf('Relation "tags" renders %s, which is neither mapped nor under resources.paths, so its type cannot be selected', TagResource::class),
            ],
        ], $this->validate(PostResource::class));
    }

    /**
     * Test that a class that is not an API resource raises nothing.
     *
     * @return void
     */
    public function testClassThatIsNotAnApiResourceRaisesNothing(): void
    {
        self::assertSame([], (new ValidateFieldSelections)->validate(\stdClass::class, null, SchemaCompiler::compile(UserResource::class)));
    }

    /**
     * Run the rule over the given resource's compiled schema.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>  $resourceClass
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    private function validate(string $resourceClass): array
    {
        return (new ValidateFieldSelections)->validate($resourceClass, null, SchemaCompiler::compile($resourceClass));
    }

    /**
     * Assert the errors match the given resource, field and defect triples.
     *
     * @param  array<int, array{string, string, string}>  $expected
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>  $errors
     * @return void
     */
    private function assertErrors(array $expected, array $errors): void
    {
        self::assertSame($expected, array_map(
            static fn (SchemaValidationError $error): array => [$error->resourceClass, $error->fieldKey, $error->defect],
            $errors,
        ));
    }
}
