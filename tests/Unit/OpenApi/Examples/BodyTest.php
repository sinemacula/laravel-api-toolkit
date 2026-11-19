<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\Body;
use SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope;

/**
 * Tests for the documented body listing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Body::class)]
final class BodyTest extends TestCase
{
    /**
     * Test that every media type of every request and response body is listed,
     * and only a JSON request or success body with a schema and no example of
     * its own takes a generated one.
     *
     * @return void
     */
    public function testEveryBodyIsListedWithTheScopeItIsGeneratedIn(): void
    {
        $schema = ['type' => 'object'];

        $bodies = Body::in(['paths' => [
            '/users/{user}' => [
                'put' => [
                    'requestBody' => ['content' => ['application/json' => ['schema' => $schema], 'multipart/form-data' => ['schema' => $schema]]],
                    'responses'   => [
                        200       => ['content' => ['application/json' => ['schema' => $schema]]],
                        204       => ['description' => 'No content.'],
                        299       => ['content' => ['application/json' => ['schema' => $schema, 'examples' => []]]],
                        300       => ['content' => ['application/json' => ['schema' => $schema]]],
                        199       => ['content' => ['application/json' => ['schema' => $schema]]],
                        404       => ['content' => ['application/json' => ['schema' => $schema]]],
                        'default' => ['content' => ['application/json' => ['schema' => $schema]]],
                    ],
                ],
                'post' => ['requestBody' => ['content' => ['application/json' => ['schema' => $schema, 'example' => []], 'text/plain' => 'none']]],
                'get'  => ['responses' => [200 => ['content' => ['application/json' => ['description' => 'No schema.']]]]],
            ],
            '/empty' => 'none',
        ]]);

        self::assertSame([
            ['PUT /users/{user} request body', ['paths', '/users/{user}', 'put', 'requestBody', 'content', 'application/json'], ExampleScope::REQUEST],
            ['PUT /users/{user} request body', ['paths', '/users/{user}', 'put', 'requestBody', 'content', 'multipart/form-data'], null],
            ['PUT /users/{user} response 200', ['paths', '/users/{user}', 'put', 'responses', 200, 'content', 'application/json'], ExampleScope::RESPONSE],
            ['PUT /users/{user} response 299', ['paths', '/users/{user}', 'put', 'responses', 299, 'content', 'application/json'], null],
            ['PUT /users/{user} response 300', ['paths', '/users/{user}', 'put', 'responses', 300, 'content', 'application/json'], null],
            ['PUT /users/{user} response 199', ['paths', '/users/{user}', 'put', 'responses', 199, 'content', 'application/json'], null],
            ['PUT /users/{user} response 404', ['paths', '/users/{user}', 'put', 'responses', 404, 'content', 'application/json'], null],
            ['PUT /users/{user} response default', ['paths', '/users/{user}', 'put', 'responses', 'default', 'content', 'application/json'], null],
            ['POST /users/{user} request body', ['paths', '/users/{user}', 'post', 'requestBody', 'content', 'application/json'], null],
            ['GET /users/{user} response 200', ['paths', '/users/{user}', 'get', 'responses', 200, 'content', 'application/json'], null],
        ], array_map(static fn (Body $body): array => [$body->target, $body->keys, $body->scope], $bodies));
        self::assertSame(['schema' => $schema], $bodies[0]->media);
    }

    /**
     * Test that a document without paths has no bodies.
     *
     * @return void
     */
    public function testDocumentWithoutPathsHasNoBodies(): void
    {
        self::assertSame([], Body::in(['openapi' => '3.1.0']));
        self::assertSame([], Body::in(['paths' => ['/users' => ['get' => 'none']]]));
    }

    /**
     * Test that the schema pointer escapes each key leading to it.
     *
     * @return void
     */
    public function testSchemaPointerEscapesEachKey(): void
    {
        $body = new Body('GET /users/{user}', ['paths', '/users/{user}', 'get', 'responses', 200, 'content', 'application/json'], [], null);

        self::assertSame('/paths/~1users~1{user}/get/responses/200/content/application~1json/schema', $body->schemaPointer());
    }
}
