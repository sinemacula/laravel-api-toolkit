<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema\Validation;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationClasses;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationInterfaces;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateRelationMethods;
use SineMacula\ApiToolkit\Schema\Validation\Rules\ValidateSensitiveColumns;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\SensitiveQueryableResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the SchemaValidator orchestrator service.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaValidator::class)]
final class SchemaValidatorTest extends TestCase
{
    /**
     * Clear the schema compiler cache before each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();
    }

    /**
     * Clear the schema compiler cache after each test.
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
     * Test that validate passes when every rule returns no errors.
     *
     * @return void
     */
    public function testValidatePassesWithNoErrors(): void
    {
        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->expects(self::once())
            ->method('validate')
            ->willReturn([]);

        $validator = new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule);

        $validator->validate([Organization::class => OrganizationResource::class]);

        self::assertTrue(true);
    }

    /**
     * Test that validate throws InvalidSchemaException when a rule errors.
     *
     * @return void
     */
    public function testValidateThrowsInvalidSchemaExceptionOnErrors(): void
    {
        $error = new SchemaValidationError(OrganizationResource::class, 'id', 'Test defect');

        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([$error]);

        $validator = new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule);

        $this->expectException(InvalidSchemaException::class);

        $validator->validate([Organization::class => OrganizationResource::class]);
    }

    /**
     * Test that the exception contains all errors from multiple rules.
     *
     * @return void
     */
    public function testExceptionContainsAllErrors(): void
    {
        $errorA = new SchemaValidationError(OrganizationResource::class, 'id', 'Defect A');
        $errorB = new SchemaValidationError(OrganizationResource::class, 'name', 'Defect B');

        $ruleA = self::createStub(SchemaValidationRule::class);

        $ruleA->method('validate')
            ->willReturn([$errorA]);

        $ruleB = self::createStub(SchemaValidationRule::class);

        $ruleB->method('validate')
            ->willReturn([$errorB]);

        $validator = new SchemaValidator(app(SchemaIntrospectionProvider::class), $ruleA, $ruleB);

        try {
            $validator->validate([Organization::class => OrganizationResource::class]);
            self::fail('Expected InvalidSchemaException was not thrown');
        } catch (InvalidSchemaException $exception) {
            self::assertCount(2, $exception->getErrors());
            self::assertSame($errorA, $exception->getErrors()[0]);
            self::assertSame($errorB, $exception->getErrors()[1]);
        }
    }

    /**
     * Test that an empty resource map calls no rules and does not throw.
     *
     * @return void
     */
    public function testValidateWithEmptyResourceMap(): void
    {
        $rule = $this->createMock(SchemaValidationRule::class);

        $rule->expects(self::never())
            ->method('validate');

        $validator = new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule);

        $validator->validate([]);

        self::assertTrue(true);
    }

    /**
     * Test that validate calls all rules for each resource in the map.
     *
     * @return void
     */
    public function testValidateCallsAllRulesForEachResource(): void
    {
        $ruleA = $this->createMock(SchemaValidationRule::class);

        $ruleA->expects(self::exactly(2))
            ->method('validate')
            ->willReturn([]);

        $ruleB = $this->createMock(SchemaValidationRule::class);

        $ruleB->expects(self::exactly(2))
            ->method('validate')
            ->willReturn([]);

        $validator = new SchemaValidator(app(SchemaIntrospectionProvider::class), $ruleA, $ruleB);

        $validator->validate([
            Organization::class => OrganizationResource::class,
            Post::class => PostResource::class,
        ]);

        self::assertTrue(true);
    }

    /**
     * Test that validate with no rules passes for a non-empty resource map.
     *
     * @return void
     */
    public function testValidateWithNoRulesPassesWithoutErrors(): void
    {
        $validator = new SchemaValidator(app(SchemaIntrospectionProvider::class));

        $validator->validate([Organization::class => OrganizationResource::class]);

        self::assertTrue(true);
    }

    /**
     * Test that errors from several resources share a single exception.
     *
     * @return void
     */
    public function testErrorsFromMultipleResourcesAreAggregated(): void
    {
        $organizationError = new SchemaValidationError(OrganizationResource::class, 'id', 'Organization defect');
        $postError = new SchemaValidationError(PostResource::class, 'title', 'Post defect');

        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturnCallback(function (string $resourceClass) use ($organizationError, $postError): array {
                if ($resourceClass === OrganizationResource::class) {
                    return [$organizationError];
                }

                return [$postError];
            });

        $validator = new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule);

        try {
            $validator->validate([
                Organization::class => OrganizationResource::class,
                Post::class => PostResource::class,
            ]);
            self::fail('Expected InvalidSchemaException was not thrown');
        } catch (InvalidSchemaException $exception) {
            self::assertCount(2, $exception->getErrors());
            self::assertSame($organizationError, $exception->getErrors()[0]);
            self::assertSame($postError, $exception->getErrors()[1]);
        }
    }

    /**
     * Test that the report keeps unverified declarations apart from defects, in
     * order, and counts every resource validated.
     *
     * @return void
     */
    public function testReportPartitionsTheFindingsAndCountsTheResources(): void
    {
        $organizationError  = new SchemaValidationError(OrganizationResource::class, 'id', 'Organization defect');
        $organizationClaim  = new UnverifiedDeclaration(OrganizationResource::class, 'name', 'a claim', Organization::class);
        $postError  = new SchemaValidationError(PostResource::class, 'title', 'Post defect');
        $postClaim  = new UnverifiedDeclaration(PostResource::class, 'body', 'another claim', Post::class);
        $byResource = [OrganizationResource::class => [$organizationClaim, $organizationError], PostResource::class => [$postError, $postClaim]];

        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturnCallback(static fn (string $resourceClass): array => $byResource[$resourceClass]);

        $report = (new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule))->report([
            Organization::class => OrganizationResource::class,
            Post::class => PostResource::class,
        ]);

        self::assertSame([$organizationError, $postError], $report->errors);
        self::assertSame([$organizationClaim, $postClaim], $report->unverified);
        self::assertSame(2, $report->resourceCount);
    }

    /**
     * Test that a catalogued resource the map does not bind is validated with
     * no model, once, and counted.
     *
     * @return void
     */
    public function testUnboundCataloguedResourceIsValidatedWithoutAModel(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [OrganizationResource::class, TagResource::class]);

        $calls = [];
        $rule  = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturnCallback(static function (string $resourceClass, ?string $modelClass) use (&$calls): array {
                $calls[] = [$resourceClass, $modelClass];

                return [];
            });

        $report = (new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule))->report([Organization::class => OrganizationResource::class]);

        self::assertSame([[OrganizationResource::class, Organization::class], [TagResource::class, null]], $calls);
        self::assertSame(2, $report->resourceCount);
    }

    /**
     * Test that a catalogued resource a filter reaches is validated with the
     * model it reaches, never again without one.
     *
     * @return void
     */
    public function testReachedCataloguedResourceIsValidatedOnlyWithItsModel(): void
    {
        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, PostResource::class]);

        $calls = [];
        $rule  = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturnCallback(static function (string $resourceClass, ?string $modelClass) use (&$calls): array {
                $calls[] = [$resourceClass, $modelClass];

                return [];
            });

        $report = (new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule))->report([User::class => UserResource::class]);

        self::assertSame([
            [UserResource::class, User::class],
            [OrganizationResource::class, Organization::class],
            [PostResource::class, Post::class],
        ], $calls);
        self::assertSame(3, $report->resourceCount);
    }

    /**
     * Test that an unverified declaration alone does not fail validation.
     *
     * @return void
     */
    public function testValidateDoesNotThrowForAnUnverifiedDeclaration(): void
    {
        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([new UnverifiedDeclaration(OrganizationResource::class, 'name', 'a claim', Organization::class)]);

        (new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule))->validate([Organization::class => OrganizationResource::class]);

        $this->expectNotToPerformAssertions();
    }

    /**
     * Test that the exception carries the defects only, never an unverified
     * declaration.
     *
     * @return void
     */
    public function testValidateThrowsWithTheDefectsOnly(): void
    {
        $error = new SchemaValidationError(OrganizationResource::class, 'id', 'Test defect');

        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturn([new UnverifiedDeclaration(OrganizationResource::class, 'name', 'a claim', Organization::class), $error]);

        try {
            (new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule))->validate([Organization::class => OrganizationResource::class]);
            self::fail('Expected InvalidSchemaException was not thrown');
        } catch (InvalidSchemaException $exception) {
            self::assertSame([$error], $exception->getErrors());
        }
    }

    /**
     * Test that a resource a filter reaches through a declared relation is
     * validated although no model maps to it.
     *
     * @return void
     */
    public function testValidatesAnUnmappedResourceAFilterReaches(): void
    {
        $posts = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'sensitive_author_posts';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(
                    Field::scalar('id'),
                    Relation::to('user', SensitiveQueryableResource::class)->traversable(),
                );
            }
        };

        $report = (new SchemaValidator(app(SchemaIntrospectionProvider::class), new ValidateSensitiveColumns))->report([Post::class => $posts::class]);

        self::assertSame(2, $report->resourceCount);
        self::assertEquals(
            [new SchemaValidationError(SensitiveQueryableResource::class, 'password', 'Field is declared filterable against "password", which is configured as a sensitive column and may never be queried')],
            $report->errors,
        );
    }

    /**
     * Test that each resource is validated once per model it serves, however
     * many relations reach it.
     *
     * @return void
     */
    public function testValidatesEachResourceAndModelPairOnce(): void
    {
        $validated = [];

        $rule = self::createStub(SchemaValidationRule::class);

        $rule->method('validate')
            ->willReturnCallback(static function (string $resourceClass, ?string $modelClass) use (&$validated): array {
                $validated[] = [$resourceClass, $modelClass];

                return [];
            });

        $report = (new SchemaValidator(app(SchemaIntrospectionProvider::class), $rule))->report([
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
        ]);

        self::assertSame([
            [UserResource::class, User::class],
            [OrganizationResource::class, Organization::class],
            [PostResource::class, Post::class],
        ], $validated);
        self::assertSame(3, $report->resourceCount);
    }

    /**
     * Test that resources reaching each other are validated once each.
     *
     * @return void
     */
    public function testTraversalCycleTerminates(): void
    {
        $users = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'cyclic_users';

            /** @var string */
            public static string $posts = '';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'), Relation::to('posts', self::$posts)->traversable());
            }
        };

        $posts = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'cyclic_posts';

            /** @var string */
            public static string $user = '';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return Field::set(Field::scalar('id'), Relation::to('user', self::$user)->traversable());
            }
        };

        $users::$posts = $posts::class;
        $posts::$user  = $users::class;

        $report = (new SchemaValidator(app(SchemaIntrospectionProvider::class)))->report([User::class => $users::class]);

        self::assertSame(2, $report->resourceCount);
    }

    /**
     * Test that a relation the model lacks, a missing resource class and a
     * class that is no resource reach nothing, and are still reported.
     *
     * @return void
     */
    public function testUnreachableRelationsAddNoResourceAndAreStillReported(): void
    {
        $posts = new class (null) extends ApiResource {
            /** @var string */
            public const string RESOURCE_TYPE = 'unreachable_posts';

            /**
             * Get the resource schema.
             *
             * @return array<string, array<string, mixed>>
             */
            #[\Override]
            public static function schema(): array
            {
                return [
                    'ghost' => ['relation' => 'ghost', 'resource' => UserResource::class, 'traversable' => 'ghost'],
                    'user'  => ['relation' => 'user', 'resource' => 'Tests\\Fixtures\\Resources\\MissingResource', 'traversable' => 'user'],
                    'tags'  => ['relation' => 'tags', 'resource' => \stdClass::class, 'traversable' => 'tags'],
                ];
            }
        };

        $validator = new SchemaValidator(
            app(SchemaIntrospectionProvider::class),
            new ValidateRelationMethods,
            new ValidateRelationClasses,
            new ValidateRelationInterfaces,
        );

        $report = $validator->report([Post::class => $posts::class]);

        self::assertSame(1, $report->resourceCount);
        self::assertSame(['ghost', 'user', 'tags'], array_map(static fn (SchemaValidationError $error): string => $error->fieldKey, $report->errors));
    }
}
