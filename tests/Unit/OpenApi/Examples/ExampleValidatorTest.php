<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleValidator;
use SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleMismatchException;

/**
 * Tests for the example validator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExampleValidator::class)]
final class ExampleValidatorTest extends TestCase
{
    /**
     * Test that a document whose examples all match passes, an example's own
     * data never read as a schema.
     *
     * @return void
     */
    public function testMatchingExamplesPass(): void
    {
        $this->expectNotToPerformAssertions();

        (new ExampleValidator)->validate(SchemaDocument::of([
            'paths' => ['/users' => ['get' => ['responses' => [
                200 => ['content' => ['application/json' => [
                    'schema'   => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'examples' => [1]], 'retired' => false]],
                    'examples' => ['default' => ['value' => ['id' => 1]]],
                ]]],
                404 => ['content' => ['application/json' => [
                    'schema'   => ['$ref' => '#/components/schemas/Error404'],
                    'examples' => ['Error404' => ['$ref' => '#/components/examples/Error404']],
                ]]],
            ]]]],
            'components' => [
                'schemas'  => ['Error404' => ['type' => 'object', 'required' => ['error']]],
                'examples' => ['Error404' => ['value' => ['error' => ['schema' => ['type' => 'string', 'examples' => [1]]]]]],
            ],
        ]), 'public');
    }

    /**
     * Provide documents carrying one mismatching example, and the message.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function mismatchProvider(): iterable
    {
        $envelope = ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'required' => ['detail']]], 'required' => ['error']];

        yield 'referenced error example' => [
            [
                'paths' => ['/users/{user}' => ['get' => ['responses' => [404 => ['content' => ['application/json' => [
                    'schema'   => ['$ref' => '#/components/schemas/Error404'],
                    'examples' => ['Error404' => ['$ref' => '#/components/examples/Error404']],
                ]]]]]]],
                'components' => ['schemas' => ['Error404' => $envelope], 'examples' => ['Error404' => ['value' => ['error' => ['code' => 404]]]]],
            ],
            'The error example [Error404] for GET /users/{user} response 404 in the [public] audience does not match its schema'
            . ' at /error: The required properties (detail) are missing',
        ];

        yield 'declared media example' => [
            ['paths' => ['/sign-in' => ['post' => ['responses' => [200 => ['content' => ['application/json' => [
                'schema'   => ['type' => 'object', 'properties' => ['status' => ['const' => 'signed_in']]],
                'examples' => ['signed-in' => ['value' => ['status' => 'pending']]],
            ]]]]]]]],
            'The declared example [signed-in] for POST /sign-in response 200 in the [public] audience does not match its schema at /status: The data must match the const value',
        ];

        yield 'deprecated media example' => [
            ['paths' => ['/members' => ['post' => ['requestBody' => ['content' => ['application/json' => [
                'schema'  => ['type' => 'object', 'required' => ['name']],
                'example' => ['nickname' => 'Ada'],
            ]]]]]]],
            'The declared example [example] for POST /members request body in the [public] audience does not match its schema'
            . ' at the root: The required properties (name) are missing',
        ];

        yield 'component property example' => [
            ['components' => ['schemas' => ['User' => ['type' => 'object', 'properties' => [
                'tier' => ['type' => 'string', 'enum' => ['gold'], 'examples' => ['gold', 'iron']],
            ]]]]],
            'The schema example [1] for #/components/schemas/User/properties/tier in the [public] audience does not match its schema'
            . ' at the root: The data should match one item from enum',
        ];

        yield 'deprecated schema example' => [
            ['components' => ['schemas' => ['Count' => ['type' => 'integer', 'example' => 'many']]]],
            'The schema example [example] for #/components/schemas/Count in the [public] audience does not match its schema'
            . ' at the root: The data (string) must match the type: integer',
        ];

        yield 'conjunct example' => [
            ['components' => ['schemas' => ['Shape' => ['allOf' => [['type' => 'object'], ['type' => 'object', 'examples' => [[]]]]]]]],
            'The schema example [0] for #/components/schemas/Shape/allOf/1 in the [public] audience does not match its schema'
            . ' at the root: The data (array) must match the type: object',
        ];

        yield 'item example' => [
            ['components' => ['schemas' => ['Tags' => ['type' => 'array', 'items' => ['type' => 'string', 'examples' => [1]]]]]],
            'The schema example [0] for #/components/schemas/Tags/items in the [public] audience does not match its schema'
            . ' at the root: The data (integer) must match the type: string',
        ];

        yield 'parameter schema example' => [
            ['paths' => ['/users' => ['get' => ['parameters' => [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'examples' => ['first']]]]]]]],
            'The schema example [0] for #/paths/~1users/get/parameters/0/schema in the [public] audience does not match its schema'
            . ' at the root: The data (string) must match the type: integer',
        ];
    }

    /**
     * Test that an example its schema rejects fails, naming its kind, name,
     * target and the failure.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $message
     * @return void
     */
    #[DataProvider('mismatchProvider')]
    public function testMismatchingExampleFails(array $document, string $message): void
    {
        $this->expectException(ExampleMismatchException::class);
        $this->expectExceptionMessage($message);

        (new ExampleValidator)->validate(SchemaDocument::of($document), 'public');
    }
}
