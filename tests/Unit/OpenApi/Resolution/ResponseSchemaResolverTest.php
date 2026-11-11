<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PendingSignInShape;
use Tests\Fixtures\OpenApi\SignedInShape;
use Tests\Fixtures\OpenApi\WidgetShape;
use Tests\Fixtures\Resources\ArticleResource;
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
    /**
     * Test that a declared registered resource resolves to its envelope.
     *
     * @return void
     */
    public function testRegisteredResourceResolvesToSingleReference(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'single');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User'),
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
            (new EnvelopeBuilder)->collectionEnvelope('#/components/schemas/User'),
            $schema,
        );
    }

    /**
     * Test that a declared DTO inlines its schema under data.
     *
     * @return void
     */
    public function testSelfDescribingClassInlinesFragment(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'widget');

        self::assertNotNull($schema);
        self::assertSame(WidgetShape::schema(), $schema['properties']['data']);
    }

    /**
     * Test that a declared plain class resolves to null.
     *
     * @return void
     */
    public function testPlainClassResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathResponseSchemaController::class, 'plain'));
    }

    /**
     * Test that a declared unmapped resource resolves to null.
     *
     * @return void
     */
    public function testUnregisteredResourceResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathResponseSchemaController::class, 'unregistered'));
    }

    /**
     * Test that two declared DTOs inline as a oneOf in declared order.
     *
     * @return void
     */
    public function testUnionOfFragmentsResolvesToOneOfInDeclaredOrder(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'union');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(['oneOf' => [SignedInShape::schema(), PendingSignInShape::schema()]]),
            $schema,
        );
    }

    /**
     * Test that a resource and DTO union references the resource component.
     *
     * @return void
     */
    public function testUnionOfResourceAndFragmentMixesReferenceAndInlineSchema(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'mixed');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(['oneOf' => [['$ref' => '#/components/schemas/User'], SignedInShape::schema()]]),
            $schema,
        );
    }

    /**
     * Test that a union collection describes each item as one of the shapes.
     *
     * @return void
     */
    public function testCollectionUnionResolvesToItemOneOf(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'unionList');

        self::assertSame(
            (new EnvelopeBuilder)->collectionEnvelopeFor(['oneOf' => [SignedInShape::schema(), PendingSignInShape::schema()]]),
            $schema,
        );
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
            (new EnvelopeBuilder)->singleEnvelope('#/components/schemas/User'),
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
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'repeated');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(['oneOf' => [SignedInShape::schema(), PendingSignInShape::schema()]]),
            $schema,
        );
    }

    /**
     * Test that a repeated member does not end the union.
     *
     * @return void
     */
    public function testRepeatedMemberStillDocumentsTheMembersAfterIt(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'repeatedBeforeAnother');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(['oneOf' => [SignedInShape::schema(), PendingSignInShape::schema()]]),
            $schema,
        );
    }

    /**
     * Test that classes resolving to one fragment collapse to the bare node.
     *
     * @return void
     */
    public function testDistinctClassesWithEqualFragmentsCollapseToBareNode(): void
    {
        $schema = $this->resolver()->resolve(PathResponseSchemaController::class, 'equivalent');

        self::assertSame(
            (new EnvelopeBuilder)->singleEnvelopeFor(SignedInShape::schema()),
            $schema,
        );
    }

    /**
     * Test that a union holding a plain class resolves to null as a whole.
     *
     * @return void
     */
    public function testUnionHoldingPlainClassResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathResponseSchemaController::class, 'partlyPlain'));
    }

    /**
     * Test that a union holding an unmapped resource resolves to null.
     *
     * @return void
     */
    public function testUnionHoldingUnregisteredResourceResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathResponseSchemaController::class, 'partlyUnregistered'));
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
     * Build a resolver whose catalogue maps the user model to its resource.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver
     */
    private function resolver(): ResponseSchemaResolver
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([User::class => UserResource::class]);

        return new ResponseSchemaResolver($catalogue, new EnvelopeBuilder);
    }
}
