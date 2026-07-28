<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\RequestBodyResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use Tests\Fixtures\OpenApi\ParityRequestBodyController;
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
     * Test that a type-hinted Payload parameter is discovered and documented as
     * the exact schema its own rules() translate to.
     *
     * @return void
     */
    public function testTypeHintedParameterIsDiscovered(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'update');

        self::assertSame([
            'required' => true,
            'content'  => [
                'application/json' => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'title'    => ['type' => 'string'],
                            'quantity' => ['type' => ['integer', 'null']],
                            'status'   => ['enum' => ['active', 'inactive']],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
        ], $body);
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
     * Test that a type-hinted FormRequest source is instantiated and documented
     * as the exact schema its rules() translate to.
     *
     * @return void
     */
    public function testFormRequestSourceResolvesJsonBody(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'formRequest');

        self::assertSame([
            'required' => true,
            'content'  => [
                'application/json' => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'name'  => ['type' => 'string', 'maxLength' => 255],
                            'email' => ['type' => 'string', 'format' => 'email'],
                        ],
                        'required' => ['name', 'email'],
                    ],
                ],
            ],
        ], $body);
    }

    /**
     * Test that a FormRequest also declaring static rules is read through its
     * static rules rather than its throwing constructor, documenting the static
     * schema.
     *
     * @return void
     */
    public function testHybridSourceReadThroughStaticRules(): void
    {
        $body = $this->resolver()->resolve(PathRequestBodyController::class, 'hybrid');

        self::assertSame([
            'required' => true,
            'content'  => [
                'application/json' => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'headline' => ['type' => 'string'],
                        ],
                        'required' => ['headline'],
                    ],
                ],
            ],
        ], $body);
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
     * Test that every discovery path documents a byte-identical body from one
     * shared rule set, the equivalence oracle across the four input flows.
     *
     * @return void
     */
    public function testAllDiscoveryPathsDocumentIdenticalBody(): void
    {
        $expected = [
            'required' => true,
            'content'  => [
                'application/json' => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'title'   => ['type' => 'string', 'maxLength' => 255],
                            'summary' => ['type' => ['string', 'null']],
                            'tags'    => ['type' => 'array', 'items' => ['type' => 'string']],
                            'status'  => ['enum' => ['active', 'inactive', 'banned']],
                            'email'   => ['type' => 'string', 'format' => 'email'],
                        ],
                        'required' => ['title', 'email'],
                    ],
                ],
            ],
        ];

        $resolver = $this->resolver();

        self::assertSame($expected, $resolver->resolve(ParityRequestBodyController::class, 'plain'));
        self::assertSame($expected, $resolver->resolve(ParityRequestBodyController::class, 'payload'));
        self::assertSame($expected, $resolver->resolve(ParityRequestBodyController::class, 'formRequest'));
        self::assertSame($expected, $resolver->resolve(ParityRequestBodyController::class, 'attribute'));
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
