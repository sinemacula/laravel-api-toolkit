<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument;
use SineMacula\ApiToolkit\OpenApi\Examples\ShapeUnionFinder;

/**
 * Tests for the finder of a body's shape union.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ShapeUnionFinder::class)]
final class ShapeUnionFinderTest extends TestCase
{
    /** The pointer of the body schema every case searches */
    private const string BODY = '/components/schemas/Body';

    /** A union of two value shapes */
    private const array UNION = ['oneOf' => [['type' => 'integer'], ['type' => 'boolean']]];

    /**
     * Provide bodies and the shape union found in each.
     *
     * @return iterable<string, array{array<string, mixed>, array{string, list<string>}|null}>
     */
    public static function bodyProvider(): iterable
    {
        yield 'own union' => [self::UNION, [self::BODY, [self::BODY . '/oneOf/0', self::BODY . '/oneOf/1']]];
        yield 'any of shapes' => [
            ['anyOf' => [['$ref' => '#/components/schemas/Shape'], ['properties' => []], ['type' => 'null']]],
            [self::BODY, [self::BODY . '/anyOf/0', self::BODY . '/anyOf/1']],
        ];
        yield 'through a reference' => [
            ['$ref' => '#/components/schemas/Shape'],
            ['/components/schemas/Shape', ['/components/schemas/Shape/anyOf/0', '/components/schemas/Shape/anyOf/1']],
        ];
        yield 'through a conjunct' => [
            ['allOf' => [['type' => 'object'], self::UNION]],
            [self::BODY . '/allOf/1', [self::BODY . '/allOf/1/oneOf/0', self::BODY . '/allOf/1/oneOf/1']],
        ];
        yield 'through a required property' => [
            ['properties' => ['id' => ['type' => 'integer'], 'data' => self::UNION], 'required' => ['data']],
            [self::BODY . '/properties/data', [self::BODY . '/properties/data/oneOf/0', self::BODY . '/properties/data/oneOf/1']],
        ];
        yield 'through items' => [['type' => 'array', 'items' => self::UNION], [self::BODY . '/items', [self::BODY . '/items/oneOf/0', self::BODY . '/items/oneOf/1']]];
        yield 'not through an optional property' => [['properties' => ['data' => self::UNION]], null];
        yield 'nullable union' => [['anyOf' => [['type' => 'integer'], ['type' => 'null']]], null];
        yield 'presence union' => [['properties' => ['a' => [], 'b' => []], 'anyOf' => [['required' => ['a']], ['required' => ['b']]]], null];
        yield 'format union' => [['type' => 'string', 'anyOf' => [['format' => 'ipv4'], ['format' => 'ipv6']]], null];
        yield 'boolean alternative' => [['oneOf' => [true, ['type' => 'integer'], ['type' => 'string']]], null];
        yield 'recursive reference' => [['$ref' => '#/components/schemas/Loop'], null];
        yield 'missing reference' => [['$ref' => '#/components/schemas/Missing'], null];
    }

    /**
     * Test that the first shape union is found depth first, with its shapes
     * other than null.
     *
     * @param  array<string, mixed>  $body
     * @param  array{string, list<string>}|null  $expected
     * @return void
     */
    #[DataProvider('bodyProvider')]
    public function testFirstShapeUnionIsFoundDepthFirst(array $body, ?array $expected): void
    {
        self::assertSame($expected, $this->finder($body)->find(self::BODY));
    }

    /**
     * Test that a shape is named by its title, its target's title or component
     * name, else its position, with an ordinal when taken.
     *
     * @return void
     */
    public function testShapeIsNamedByItsTitleTargetOrPosition(): void
    {
        $finder = $this->finder(['oneOf' => [
            ['title' => 'Signed in', 'type' => 'object'],
            ['$ref' => '#/components/schemas/Titled'],
            ['$ref' => '#/components/schemas/Shape'],
            ['$ref' => '#/components/schemas/a~1b'],
            ['type' => 'object'],
        ]]);

        self::assertSame('Signed in', $finder->name(self::BODY . '/oneOf/0', 0, []));
        self::assertSame('Signed in 2', $finder->name(self::BODY . '/oneOf/0', 0, ['Signed in' => 1]));
        self::assertSame('Signed in 3', $finder->name(self::BODY . '/oneOf/0', 0, ['Signed in' => 1, 'Signed in 2' => 2]));
        self::assertSame('Awaiting a code', $finder->name(self::BODY . '/oneOf/1', 1, []));
        self::assertSame('Shape', $finder->name(self::BODY . '/oneOf/2', 2, []));
        self::assertSame('a/b', $finder->name(self::BODY . '/oneOf/3', 3, []));
        self::assertSame('Alternative 5', $finder->name(self::BODY . '/oneOf/4', 4, []));
    }

    /**
     * Build a finder over a document holding the body schema.
     *
     * @param  array<string, mixed>  $body
     * @return \SineMacula\ApiToolkit\OpenApi\Examples\ShapeUnionFinder
     */
    private function finder(array $body): ShapeUnionFinder
    {
        return new ShapeUnionFinder(SchemaDocument::of(['components' => ['schemas' => [
            'Body'   => $body,
            'Shape'  => ['anyOf' => [['type' => 'integer'], ['type' => 'string']]],
            'Titled' => ['title' => 'Awaiting a code', 'type' => 'object'],
            'Loop'   => ['$ref' => '#/components/schemas/Loop'],
        ]]]));
    }
}
