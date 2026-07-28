<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use Tests\Fixtures\OpenApi\PathRequestBodyController;
use Tests\TestCase;

/**
 * Tests for the request-body resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RequestBodyResolver::class)]
final class RequestBodyResolverTest extends TestCase
{
    /**
     * Test that a directive-named source resolves a required JSON body carrying
     * the translated schema.
     *
     * @return void
     */
    public function testDirectiveSourceResolvesJsonBody(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'store');

        self::assertNotNull($body);
        self::assertTrue($body['required']);
        self::assertArrayHasKey('application/json', $body['content']);
        self::assertArrayHasKey('title', $body['content']['application/json']['schema']['properties']);
    }

    /**
     * Test that a type-hinted rules-source parameter is discovered when the
     * action carries no directive.
     *
     * @return void
     */
    public function testTypeHintedParameterIsDiscovered(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'update');

        self::assertNotNull($body);

        $properties = $body['content']['application/json']['schema']['properties'];

        self::assertArrayHasKey('title', $properties);
        self::assertArrayHasKey('quantity', $properties);
    }

    /**
     * Test that a directive wins over a competing type-hinted parameter.
     *
     * @return void
     */
    public function testDirectiveWinsOverTypeHint(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'both');

        self::assertNotNull($body);

        $properties = $body['content']['application/json']['schema']['properties'];

        self::assertArrayHasKey('summary', $properties);
        self::assertArrayNotHasKey('quantity', $properties);
    }

    /**
     * Test that an action with neither a directive nor a rules-source parameter
     * resolves to null.
     *
     * @return void
     */
    public function testActionWithoutSourceResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathRequestBodyController::class, 'index'));
    }

    /**
     * Test that a source carrying a binary field is served as multipart form
     * data.
     *
     * @return void
     */
    public function testBinaryFieldForcesMultipart(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'upload');

        self::assertNotNull($body);
        self::assertArrayHasKey('multipart/form-data', $body['content']);
    }

    /**
     * Test that a FormRequest whose rules() throws degrades to no body rather
     * than surfacing the failure.
     *
     * @return void
     */
    public function testThrowingFormRequestResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathRequestBodyController::class, 'throwing'));
    }

    /**
     * Test that a closure route, which has no controller class, resolves to
     * null.
     *
     * @return void
     */
    public function testClosureRouteResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(null, 'store'));
    }

    /**
     * Test that a type-hinted FormRequest source is instantiated and its rules
     * documented as a required JSON body.
     *
     * @return void
     */
    public function testFormRequestSourceResolvesJsonBody(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'formRequest');

        self::assertNotNull($body);
        self::assertTrue($body['required']);
        self::assertArrayHasKey('application/json', $body['content']);

        $properties = $body['content']['application/json']['schema']['properties'];

        self::assertArrayHasKey('name', $properties);
        self::assertArrayHasKey('email', $properties);
    }

    /**
     * Test that a FormRequest also declaring static rules is read through its
     * static rules rather than instantiated.
     *
     * @return void
     */
    public function testHybridSourceReadThroughStaticRules(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'hybrid');

        self::assertNotNull($body);

        $properties = $body['content']['application/json']['schema']['properties'];

        self::assertArrayHasKey('headline', $properties);
    }

    /**
     * Test that a FormRequest whose rules() yields a non-array value degrades
     * to no documented body.
     *
     * @return void
     */
    public function testNonArrayFormRequestResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathRequestBodyController::class, 'nonArray'));
    }

    /**
     * Test that a parameter whose type is not a single named class names no
     * rules source and resolves to null.
     *
     * @return void
     */
    public function testNonNamedTypeParameterResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve(PathRequestBodyController::class, 'unionParam'));
    }

    /**
     * Build a resolver over the real translator graph.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver
     */
    private function resolver(): RequestBodyResolver
    {
        return new RequestBodyResolver(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder));
    }
}
