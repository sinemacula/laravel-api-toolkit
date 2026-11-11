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
     * Provide the subschema keywords and their empty-value encoding.
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
     * Test that an empty subschema at any depth encodes as an object.
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
     * Test that a map keyed by consecutive integers encodes as an object.
     *
     * @return void
     */
    public function testAMapOfNumericNamesEncodesAsAnObject(): void
    {
        $document = ['components' => ['schemas' => ['A' => [
            'properties'        => [['type' => 'string'], []],
            'dependentRequired' => [['1'], []],
            'required'          => ['0', '1'],
        ]]]];

        self::assertSame(
            '{"components":{"schemas":{"A":{"properties":{"0":{"type":"string"},"1":{}},"dependentRequired":{"0":["1"],"1":[]},"required":["0","1"]}}}}',
            $this->encode($document),
        );
    }

    /**
     * Test that an empty `schema` value encodes as an object everywhere.
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
     * Test that component schemas and their empty schemas encode as objects.
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
     * Test that non-schema values keep their empty arrays.
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
     * Test that a non-array value is passed over without ending the walk.
     *
     * @return void
     */
    public function testScalarValuesDoNotEndTheWalk(): void
    {
        $document = [
            'openapi'    => '3.1.0',
            'components' => [
                'version'    => 1,
                'parameters' => ['q' => ['schema' => []]],
            ],
            'paths' => ['/a' => ['get' => ['parameters' => [['schema' => []]]]]],
        ];

        self::assertSame(
            '{"openapi":"3.1.0","components":{"version":1,"parameters":{"q":{"schema":{}}}},"paths":{"/a":{"get":{"parameters":[{"schema":{}}]}}}}',
            $this->encode($document),
        );
    }

    /**
     * Test that a named map entry is read by its structure, not its name.
     *
     * @return void
     */
    public function testNamedMapEntriesNamedLikeKeywordsAreReadByStructure(): void
    {
        $document = [
            'webhooks' => ['schema' => ['post' => ['requestBody' => ['content' => ['example' => ['schema' => []]]]]]],
            'paths'    => [
                '/a' => [
                    'get' => [
                        'responses' => ['examples' => ['headers' => ['schema' => ['schema' => ['not' => []]], 'example' => ['schema' => []]]]],
                    ],
                ],
            ],
            'components' => ['parameters' => ['schema' => ['schema' => []], 'flag' => true]],
        ];

        self::assertSame(
            '{"webhooks":{"schema":{"post":{"requestBody":{"content":{"example":{"schema":{}}}}}}},'
            . '"paths":{"/a":{"get":{"responses":{"examples":{"headers":{"schema":{"schema":{"not":{}}},"example":{"schema":{}}}}}}}},'
            . '"components":{"parameters":{"schema":{"schema":{}},"flag":true}}}',
            $this->encode($document),
        );
    }

    /**
     * Test that example components are left untouched as example data.
     *
     * @return void
     */
    public function testExampleComponentsAreLeftUntouched(): void
    {
        $document = ['components' => ['examples' => ['a' => ['value' => ['schema' => []]]], 'headers' => ['X' => ['schema' => []]]]];

        self::assertSame('{"components":{"examples":{"a":{"value":{"schema":[]}}},"headers":{"X":{"schema":{}}}}}', $this->encode($document));
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
