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
     * Test that an action declaring a registered resource resolves to a single
     * envelope referencing that resource's component schema.
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
     * Test that an action declaring a registered resource as a collection
     * resolves to the length-aware collection envelope for that resource.
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
     * Test that an action declaring a self-describing DTO inlines its schema
     * fragment under the single envelope's data property.
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
     * Test that an action declaring a plain class that is neither a resource
     * nor self-describing resolves to null.
     *
     * @return void
     */
    public function testPlainClassResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathResponseSchemaController::class, 'plain'));
    }

    /**
     * Test that an action declaring a resource absent from the resource map
     * resolves to null rather than emitting a reference that would dangle.
     *
     * @return void
     */
    public function testUnregisteredResourceResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathResponseSchemaController::class, 'unregistered'));
    }

    /**
     * Test that an action naming two self-describing classes inlines both
     * fragments as a oneOf under the single envelope's data property, in the
     * order they were declared.
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
     * Test that a union of a registered resource and a self-describing class
     * references the resource's component beside the inlined fragment.
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
     * Test that a union declared as a collection describes each item of the
     * list as one of the shapes, rather than the list as a whole.
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
     * Test that a union naming one class twice collapses to the bare node, so
     * it documents exactly what naming the class once does.
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
     * Test that a member repeated later in a union is dropped, keeping each
     * distinct shape once at the position it was first declared.
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
     * Test that a repeated member does not end the union, so the shapes named
     * after it are still documented.
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
     * Test that distinct classes resolving to the same fragment collapse to the
     * bare node, so no response can match more than one branch of the union.
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
     * Test that a union holding a plain class resolves to null as a whole,
     * rather than documenting only the members it can describe.
     *
     * @return void
     */
    public function testUnionHoldingPlainClassResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathResponseSchemaController::class, 'partlyPlain'));
    }

    /**
     * Test that a union holding a resource absent from the resource map
     * resolves to null as a whole, rather than documenting only the members it
     * can describe.
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
     * Test that a closure route, which has no controller class, resolves to
     * null.
     *
     * @return void
     */
    public function testClosureRouteResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(null, 'single'));
    }

    /**
     * Test that an action carrying a directive declares a body even when a
     * class it names cannot be described.
     *
     * @return void
     */
    public function testDirectiveDeclaresABodyWhetherOrNotItResolves(): void
    {
        self::assertTrue($this->resolver()->declares(PathResponseSchemaController::class, 'widget'));
        self::assertTrue($this->resolver()->declares(PathResponseSchemaController::class, 'plain'));
    }

    /**
     * Test that an action carrying no directive, or a closure route, declares
     * no body.
     *
     * @return void
     */
    public function testUndeclaredActionDeclaresNoBody(): void
    {
        self::assertFalse($this->resolver()->declares(PathResponseSchemaController::class, 'bare'));
        self::assertFalse($this->resolver()->declares(null, 'widget'));
    }

    /**
     * Build a resolver backed by a catalogue mapping the user model to its
     * resource.
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
