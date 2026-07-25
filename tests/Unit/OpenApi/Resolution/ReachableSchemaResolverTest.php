<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Resolution;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Resolution\ReachableSchemaResolver;
use Tests\TestCase;

/**
 * Tests for the reachable schema resolver.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ReachableSchemaResolver::class)]
final class ReachableSchemaResolverTest extends TestCase
{
    /**
     * Test that a schema referenced from the seed is retained while an
     * unreferenced schema is dropped.
     *
     * @return void
     */
    public function testKeepsSeededSchemaAndDropsUnreachable(): void
    {
        $schemas = [
            'User' => ['type' => 'object'],
            'Tag'  => ['type' => 'object'],
        ];

        $seed = [['$ref' => '#/components/schemas/User']];

        $result = (new ReachableSchemaResolver)->reachable($schemas, $seed, []);

        self::assertArrayHasKey('User', $result);
        self::assertArrayNotHasKey('Tag', $result);
    }

    /**
     * Test that the transitive closure follows references between schemas.
     *
     * @return void
     */
    public function testFollowsTransitiveReferences(): void
    {
        $schemas = [
            'User'         => ['properties' => ['org' => ['$ref' => '#/components/schemas/Organization']]],
            'Organization' => ['properties' => ['post' => ['$ref' => '#/components/schemas/Post']]],
            'Post'         => ['type' => 'object'],
            'Tag'          => ['type' => 'object'],
        ];

        $seed = [['$ref' => '#/components/schemas/User']];

        $result = (new ReachableSchemaResolver)->reachable($schemas, $seed, []);

        self::assertSame(['User', 'Organization', 'Post'], array_keys($result));
    }

    /**
     * Test that always-kept schema names are retained even when nothing
     * references them.
     *
     * @return void
     */
    public function testAlwaysKeepSchemasAreRetained(): void
    {
        $schemas = [
            'User'          => ['type' => 'object'],
            'ErrorEnvelope' => ['type' => 'object'],
        ];

        $result = (new ReachableSchemaResolver)->reachable($schemas, [], ['ErrorEnvelope']);

        self::assertArrayHasKey('ErrorEnvelope', $result);
        self::assertArrayNotHasKey('User', $result);
    }

    /**
     * Test that a reference to a schema absent from the map is ignored rather
     * than surfacing a phantom entry.
     *
     * @return void
     */
    public function testDanglingReferenceIsIgnored(): void
    {
        $schemas = [
            'User' => ['properties' => ['ghost' => ['$ref' => '#/components/schemas/Missing']]],
        ];

        $seed = [['$ref' => '#/components/schemas/User']];

        $result = (new ReachableSchemaResolver)->reachable($schemas, $seed, []);

        self::assertSame(['User'], array_keys($result));
    }

    /**
     * Test that references nested deep within the seed are discovered.
     *
     * @return void
     */
    public function testDiscoversDeeplyNestedReferences(): void
    {
        $schemas = ['Tag' => ['type' => 'object']];

        $seed = [
            'get' => [
                'responses' => [
                    '200' => [
                        'content' => [
                            'application/json' => [
                                'schema' => ['items' => ['$ref' => '#/components/schemas/Tag']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = (new ReachableSchemaResolver)->reachable($schemas, $seed, []);

        self::assertArrayHasKey('Tag', $result);
    }

    /**
     * Test that only references under the component-schema prefix are followed,
     * leaving other reference kinds untouched.
     *
     * @return void
     */
    public function testIgnoresNonSchemaReferences(): void
    {
        $schemas = ['User' => ['type' => 'object']];

        $seed = [['$ref' => '#/components/headers/Total-Count']];

        $result = (new ReachableSchemaResolver)->reachable($schemas, $seed, []);

        self::assertSame([], $result);
    }
}
