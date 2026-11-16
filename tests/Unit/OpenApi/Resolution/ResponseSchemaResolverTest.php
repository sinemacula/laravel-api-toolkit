<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry;
use Tests\Fixtures\Enums\SelfDescribingPlan;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\IntegerVersionShape;
use Tests\Fixtures\OpenApi\MfaChallengeShape;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PendingSignInShape;
use Tests\Fixtures\OpenApi\SignedInShape;
use Tests\Fixtures\OpenApi\StringVersionShape;
use Tests\Fixtures\OpenApi\WidgetShape;
use Tests\Fixtures\Resources\ArticleResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PlainJsonResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the response-schema resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ResponseSchemaResolver::class)]
final class ResponseSchemaResolverTest extends TestCase
{
    /** The path prefix under which component schemas are referenced */
    private const string PREFIX = '#/components/schemas/';

    /**
     * Test that a declared registered resource resolves to its envelope.
     *
     * @return void
     */
    public function testRegisteredResourceResolvesToSingleReference(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'single');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope(self::PREFIX . 'User'),
            $schema,
        );
    }

    /**
     * Test that a declared resource collection resolves to its envelope.
     *
     * @return void
     */
    public function testRegisteredResourceCollectionResolvesToCollectionEnvelope(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'list');

        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelope(self::PREFIX . 'User'),
            $schema,
        );
    }

    /**
     * Test that a declared DTO references its component under data.
     *
     * @return void
     */
    public function testSelfDescribingClassResolvesToComponentReference(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'widget');

        self::assertNotNull($schema);
        self::assertSame(['$ref' => self::PREFIX . 'WidgetShape'], $schema['properties']['data']);
        self::assertSame([WidgetShape::class], $registry->classes());
    }

    /**
     * Test that a declared DTO collection references its component per item.
     *
     * @return void
     */
    public function testSelfDescribingCollectionReferencesComponentPerItem(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'widgets');

        self::assertSame((new EnvelopeBuilder)->collectionEnvelope(self::PREFIX . 'WidgetShape'), $schema);
        self::assertSame([WidgetShape::class], $registry->classes());
    }

    /**
     * Test that a declared plain class resolves to null.
     *
     * @return void
     */
    public function testPlainClassResolvesToNull(): void
    {
        $registry = new DefinedSchemaRegistry;

        self::assertNull($this->resolver($registry)->resolve(PathResponseSchemaController::class, 'plain'));
        self::assertSame([], $registry->classes());
    }

    /**
     * Test that a declared enum implementing DefinesSchema references its
     * component.
     *
     * @return void
     */
    public function testSelfDescribingEnumResolvesToComponentReference(): void
    {
        $registry = new DefinedSchemaRegistry;

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope(self::PREFIX . 'SelfDescribingPlan'),
            $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'enum'),
        );
        self::assertSame([SelfDescribingPlan::class], $registry->classes());
    }

    /**
     * Test that a declared unmapped resource resolves to null.
     *
     * @return void
     */
    public function testUnregisteredResourceResolvesToNull(): void
    {
        $registry = new DefinedSchemaRegistry;

        self::assertNull($this->resolver($registry)->resolve(PathResponseSchemaController::class, 'unregistered'));
        self::assertSame([], $registry->classes());
    }

    /**
     * Test that two declared DTOs resolve to a discriminated oneOf of their
     * references in declared order.
     *
     * @return void
     */
    public function testUnionOfShapesResolvesToDiscriminatedOneOfInDeclaredOrder(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'union');

        self::assertSame((new EnvelopeBuilder)->singleEnvelopeFor($this->signInUnion()), $schema);
        self::assertSame([SignedInShape::class, PendingSignInShape::class], $registry->classes());
    }

    /**
     * Test that a resource and DTO union references both components, with no
     * discriminator.
     *
     * @return void
     */
    public function testUnionOfResourceAndShapeReferencesBothComponents(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'mixed');

        self::assertSame((new EnvelopeBuilder)->singleEnvelopeFor($this->union(['User', 'SignedInShape'])), $schema);
        self::assertSame([SignedInShape::class], $registry->classes());
    }

    /**
     * Test that a union collection describes each item as one of the shapes.
     *
     * @return void
     */
    public function testCollectionUnionResolvesToItemOneOf(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'unionList');

        self::assertSame((new EnvelopeBuilder)->collectionEnvelopeFor($this->signInUnion()), $schema);
        self::assertSame([SignedInShape::class, PendingSignInShape::class], $registry->classes());
    }

    /**
     * Test that a union naming one class twice collapses to the bare node.
     *
     * @return void
     */
    public function testDuplicatedMemberCollapsesToBareNode(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'duplicated');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope(self::PREFIX . 'User'),
            $schema,
        );
    }

    /**
     * Test that a repeated union member is dropped after its first position.
     *
     * @return void
     */
    public function testRepeatedMemberKeepsItsFirstPosition(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'repeated');

        self::assertSame((new EnvelopeBuilder)->singleEnvelopeFor($this->signInUnion()), $schema);
        self::assertSame([SignedInShape::class, PendingSignInShape::class], $registry->classes());
    }

    /**
     * Test that a repeated member does not end the union.
     *
     * @return void
     */
    public function testRepeatedMemberStillDocumentsTheMembersAfterIt(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'repeatedBeforeAnother');

        self::assertSame((new EnvelopeBuilder)->singleEnvelopeFor($this->signInUnion()), $schema);
        self::assertSame([SignedInShape::class, PendingSignInShape::class], $registry->classes());
    }

    /**
     * Test that classes resolving to one fragment collapse to the first class's
     * reference.
     *
     * @return void
     */
    public function testDistinctClassesWithEqualFragmentsCollapseToBareNode(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'equivalent');

        self::assertSame((new EnvelopeBuilder)->singleEnvelope(self::PREFIX . 'SignedInShape'), $schema);
        self::assertSame([SignedInShape::class], $registry->classes());
    }

    /**
     * Test that fragments differing only in a constant's type stay distinct
     * members.
     *
     * @return void
     */
    public function testLooselyEqualFragmentsStayDistinctMembers(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'looselyEqual');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor($this->union(['IntegerVersionShape', 'StringVersionShape'])),
            $schema,
        );
        self::assertSame([IntegerVersionShape::class, StringVersionShape::class], $registry->classes());
    }

    /**
     * Test that a #[SchemaName] override names the member's reference and
     * mapping.
     *
     * @return void
     */
    public function testSchemaNameOverrideNamesTheMemberReference(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'named');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor($this->union(['SignedInShape', 'MfaChallenge'], 'status', [
                'signed_in'    => 'SignedInShape',
                'mfa_required' => 'MfaChallenge',
            ])),
            $schema,
        );
        self::assertSame([SignedInShape::class, MfaChallengeShape::class], $registry->classes());
    }

    /**
     * Test that members pinning one value leave the union undiscriminated.
     *
     * @return void
     */
    public function testMembersSharingTheirConstantAreNotDiscriminated(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'clashing');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor($this->union(['SignedInShape', 'TokenRefreshedShape'])),
            $schema,
        );
    }

    /**
     * Test that a first member pinning nothing leaves the union
     * undiscriminated.
     *
     * @return void
     */
    public function testUnpinnedFirstMemberIsNotDiscriminated(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'unpinned');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor($this->union(['WidgetShape', 'SignedInShape'])),
            $schema,
        );
    }

    /**
     * Test that a union of API resources is discriminated on their type.
     *
     * @return void
     */
    public function testResourceUnionIsDiscriminatedOnTheResourceType(): void
    {
        $registry = new DefinedSchemaRegistry;
        $schema   = $this->resolver($registry)->resolve(PathResponseSchemaController::class, 'resources');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor($this->union(['User', 'Organization'], '_type', [
                'users'         => 'User',
                'organizations' => 'Organization',
            ])),
            $schema,
        );
        self::assertSame([], $registry->classes());
    }

    /**
     * Test that a registered resource declaring no type is not discriminated.
     *
     * @return void
     */
    public function testResourceDeclaringNoTypeIsNotDiscriminated(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'plainResource');

        self::assertSame((new EnvelopeBuilder)->singleEnvelopeFor($this->union(['User', 'PlainJson'])), $schema);
    }

    /**
     * Test that a union holding a plain class resolves to null as a whole.
     *
     * @return void
     */
    public function testUnionHoldingPlainClassResolvesToNull(): void
    {
        $registry = new DefinedSchemaRegistry;

        self::assertNull($this->resolver($registry)->resolve(PathResponseSchemaController::class, 'partlyPlain'));
        self::assertSame([], $registry->classes());
    }

    /**
     * Test that a union holding an unmapped resource resolves to null.
     *
     * @return void
     */
    public function testUnionHoldingUnregisteredResourceResolvesToNull(): void
    {
        $registry = new DefinedSchemaRegistry;

        self::assertNull($this->resolver($registry)->resolve(PathResponseSchemaController::class, 'partlyUnregistered'));
        self::assertSame([], $registry->classes());
    }

    /**
     * Test that an action carrying no directive resolves to null.
     *
     * @return void
     */
    public function testActionWithoutDirectiveResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathResponseSchemaController::class, 'bare'));
    }

    /**
     * Test that a closure route resolves to null.
     *
     * @return void
     */
    public function testClosureRouteResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(null, 'single'));
    }

    /**
     * Test that a directive declares a body even for an undescribable class.
     *
     * @return void
     */
    public function testDirectiveDeclaresABodyWhetherOrNotItResolves(): void
    {
        self::assertTrue($this->resolver()->declares(PathResponseSchemaController::class, 'widget'));
        self::assertTrue($this->resolver()->declares(PathResponseSchemaController::class, 'plain'));
    }

    /**
     * Test that no directive, or a closure route, declares no body.
     *
     * @return void
     */
    public function testUndeclaredActionDeclaresNoBody(): void
    {
        self::assertFalse($this->resolver()->declares(PathResponseSchemaController::class, 'bare'));
        self::assertFalse($this->resolver()->declares(null, 'widget'));
    }

    /**
     * Test that a directive naming only the resource declares it.
     *
     * @return void
     */
    public function testDirectiveNamingTheResourceAloneInTheShapeDeclaresIt(): void
    {
        $resolver = $this->resolver();

        self::assertTrue($resolver->declaresResource(PathResponseSchemaController::class, 'single', UserResource::class, false));
        self::assertTrue($resolver->declaresResource(PathResponseSchemaController::class, 'list', UserResource::class, true));
        self::assertTrue($resolver->declaresResource(PathResponseSchemaController::class, 'duplicated', UserResource::class, false));
    }

    /**
     * Test that a directive declares no resource it names otherwise.
     *
     * @return void
     */
    public function testDirectiveNamingAnythingElseDoesNotDeclareTheResource(): void
    {
        $resolver = $this->resolver();

        self::assertFalse($resolver->declaresResource(PathResponseSchemaController::class, 'single', UserResource::class, true));
        self::assertFalse($resolver->declaresResource(PathResponseSchemaController::class, 'list', UserResource::class, false));
        self::assertFalse($resolver->declaresResource(PathResponseSchemaController::class, 'mixed', UserResource::class, false));
        self::assertFalse($resolver->declaresResource(PathResponseSchemaController::class, 'single', ArticleResource::class, false));
        self::assertFalse($resolver->declaresResource(PathResponseSchemaController::class, 'bare', UserResource::class, false));
        self::assertFalse($resolver->declaresResource(null, 'single', UserResource::class, false));
    }

    /**
     * Build the discriminated union of the signed-in and pending shapes.
     *
     * @return array<string, mixed>
     */
    private function signInUnion(): array
    {
        return $this->union(['SignedInShape', 'PendingSignInShape'], 'status', [
            'signed_in' => 'SignedInShape',
            'pending'   => 'PendingSignInShape',
        ]);
    }

    /**
     * Build the expected `oneOf` of the named components, discriminated when a
     * property is given.
     *
     * @param  list<string>  $names
     * @param  string|null  $property
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function union(array $names, ?string $property = null, array $mapping = []): array
    {
        $union = ['oneOf' => array_map(static fn (string $name): array => ['$ref' => self::PREFIX . $name], $names)];

        if ($property !== null) {
            $union['discriminator'] = [
                'propertyName' => $property,
                'mapping'      => array_map(static fn (string $name): string => self::PREFIX . $name, $mapping),
            ];
        }

        return $union;
    }

    /**
     * Build a resolver whose catalogue maps the user, organization and tag
     * models to their resources.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry  $registry
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver
     */
    private function resolver(DefinedSchemaRegistry $registry = new DefinedSchemaRegistry): ResponseSchemaResolver
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Tag::class          => PlainJsonResource::class,
        ]);

        return new ResponseSchemaResolver($catalogue, new EnvelopeBuilder, $registry);
    }
}
