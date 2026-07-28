<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
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
