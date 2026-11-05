<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\OpenApi\Schema\SchemaObjectNormaliser;
use Tests\TestCase;

/**
 * Tests for the schema object normaliser.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SchemaObjectNormaliser::class)]
final class SchemaObjectNormaliserTest extends TestCase
{
    /**
     * Provide the subschema keywords and the encoding of an empty value under
     * each.
     *
     * @return iterable<string, array{0: string, 1: array<string, mixed>|list<mixed>, 2: string}>
     */
    public static function subschemaProvider(): iterable
    {
        $keywords = [
            'items', 'not', 'additionalProperties', 'contains', 'propertyNames', 'if', 'then', 'else',
            'unevaluatedItems', 'unevaluatedProperties', 'contentSchema',
        ];

        foreach ($keywords as $keyword) {
            yield $keyword => [$keyword, ['properties' => []], '{"properties":{}}'];
        }

        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $keyword) {
            yield $keyword => [$keyword, [[], ['type' => 'string'], false], '[{},{"type":"string"},false]'];
        }

        foreach (['properties', 'patternProperties', 'dependentSchemas', '$defs'] as $keyword) {
            yield $keyword => [$keyword, ['a' => [], 'b' => true, 'c' => ['items' => []]], '{"a":{},"b":true,"c":{"items":{}}}'];
        }
    }

    /**
     * Test that an empty subschema under each subschema keyword, at any depth,
     * encodes as an empty object.
     *
     * @param  string  $keyword
     * @param  array<string, mixed>|list<mixed>  $value
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('subschemaProvider')]
    public function testEmptySubschemasEncodeAsObjects(string $keyword, array $value, string $expected): void
    {
        $document = ['paths' => ['/a' => ['post' => ['requestBody' => ['content' => ['application/json' => ['schema' => [$keyword => $value]]]]]]]];

        self::assertSame(
            '{"paths":{"/a":{"post":{"requestBody":{"content":{"application/json":{"schema":{"' . $keyword . '":' . $expected . '}}}}}}}}',
            $this->encode($document),
        );
    }

    /**
     * Test that an empty map of named subschemas encodes as an empty object.
     *
     * @return void
     */
    public function testAnEmptySubschemaMapEncodesAsAnObject(): void
    {
        $document = ['components' => ['schemas' => ['A' => ['type' => 'object', 'properties' => [], '$defs' => []]]]];

        self::assertSame('{"components":{"schemas":{"A":{"type":"object","properties":{},"$defs":{}}}}}', $this->encode($document));
    }

    /**
     * Test that an empty schema anywhere a `schema` key holds one encodes as an
     * empty object, in the paths and in the components alike.
     *
     * @return void
     */
    public function testAnEmptySchemaUnderASchemaKeyEncodesAsAnObject(): void
    {
        $document = [
            'paths' => [
                '/a' => [
                    'get' => [
                        'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => []]],
                        'responses'  => ['200' => ['headers' => ['X-A' => ['schema' => []]], 'content' => ['application/json' => ['schema' => []]]]],
                    ],
                ],
            ],
            'components' => [
                'parameters' => ['q' => ['name' => 'q', 'in' => 'query', 'schema' => []]],
                'headers'    => ['X-A' => ['schema' => ['items' => []]]],
            ],
        ];

        self::assertSame(
            '{"paths":{"/a":{"get":{"parameters":[{"name":"q","in":"query","schema":{}}],'
            . '"responses":{"200":{"headers":{"X-A":{"schema":{}}},"content":{"application/json":{"schema":{}}}}}}}},'
            . '"components":{"parameters":{"q":{"name":"q","in":"query","schema":{}}},"headers":{"X-A":{"schema":{"items":{}}}}}}',
            $this->encode($document),
        );
    }

    /**
     * Test that the component schemas map and every empty schema it holds
     * encode as objects.
     *
     * @return void
     */
    public function testComponentSchemasEncodeAsObjects(): void
    {
        self::assertSame('{"components":{"schemas":{}}}', $this->encode(['components' => ['schemas' => []]]));
        self::assertSame(
            '{"components":{"schemas":{"Any":{},"Never":false,"Ref":{"anyOf":[{"$ref":"#/x"},{}]}}}}',
            $this->encode(['components' => ['schemas' => ['Any' => [], 'Never' => false, 'Ref' => ['anyOf' => [['$ref' => '#/x'], []]]]]]),
        );
    }

    /**
     * Test that values that are not schemas keep their empty arrays: keywords
     * carrying data, examples, and maps named like schema keywords outside a
     * schema.
     *
     * @return void
     */
    public function testValuesThatAreNotSchemasKeepTheirEmptyArrays(): void
    {
        $document = [
            'openapi'    => '3.1.0',
            'info'       => ['schemas' => ['A' => []], 'items' => []],
            'paths'      => new \stdClass,
            'tags'       => [],
            'components' => [
                'version'   => 1,
                'responses' => ['400' => ['content' => ['application/json' => [
                    'schema'   => ['enum' => [], 'default' => [], 'const' => [], 'required' => [], 'x-relations' => [], 'type' => 'array'],
                    'example'  => ['schema' => []],
                    'examples' => ['a' => ['value' => ['schema' => []]]],
                ]]]],
                'security' => [[]],
            ],
        ];

        self::assertSame(
            '{"openapi":"3.1.0","info":{"schemas":{"A":[]},"items":[]},"paths":{},"tags":[],"components":{"version":1,"responses":{"400":{"content":{"application/json":{'
            . '"schema":{"enum":[],"default":[],"const":[],"required":[],"x-relations":[],"type":"array"},'
            . '"example":{"schema":[]},"examples":{"a":{"value":{"schema":[]}}}}}}},"security":[[]]}}',
            $this->encode($document),
        );
    }

    /**
     * Normalise the document and encode it as JSON.
     *
     * @param  array<string, mixed>  $document
     * @return string
     */
    private function encode(array $document): string
    {
        return json_encode((new SchemaObjectNormaliser)->normalise($document), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
