<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleGenerator;
use SineMacula\ApiToolkit\OpenApi\Examples\MissingValue;
use SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument;

/**
 * Tests for the example generator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExampleGenerator::class)]
final class ExampleGeneratorTest extends TestCase
{
    /** The pointer of the body schema every case generates for */
    private const string BODY = '/components/schemas/Body';

    /**
     * Provide schemas and the value each generates.
     *
     * @return iterable<string, array{array<string, mixed>, mixed}>
     */
    public static function valueProvider(): iterable
    {
        yield 'integer' => [['type' => 'integer'], 1];
        yield 'number' => [['type' => 'number'], 1.5];
        yield 'boolean' => [['type' => 'boolean'], true];
        yield 'null' => [['type' => 'null'], null];
        yield 'untyped' => [['description' => 'Anything.'], 'string'];
        yield 'typeless format' => [['format' => 'ipv4'], '192.0.2.1'];
        yield 'list' => [['type' => 'array', 'items' => ['type' => 'integer']], [1]];
        yield 'list of the fewest' => [['type' => 'array', 'items' => ['type' => 'boolean'], 'minItems' => 2], [true, true]];
        yield 'list without items' => [['type' => 'array'], ['string']];
        yield 'list of none' => [['type' => 'array', 'maxItems' => 0], []];
        yield 'typeless list' => [['items' => ['type' => 'integer']], [1]];
        yield 'typeless object' => [['properties' => ['a' => ['type' => 'integer']]], ['a' => 1]];
        yield 'list up to its maximum' => [['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 5], [1]];
        yield 'empty list of undocumented items' => [['type' => 'array', 'items' => ['x-undocumented' => true], 'maxItems' => 0], []];
        yield 'null type when nothing else' => [['type' => ['null', 'string'], 'pattern' => '^x$'], null];
        yield 'true alternative' => [['anyOf' => [true, ['type' => 'integer']]], 'string'];
        yield 'null alternative when nothing else' => [['oneOf' => [['type' => 'string', 'pattern' => '^x$'], ['type' => 'null']]], null];
        yield 'union value after an undocumented alternative' => [
            ['oneOf' => [['x-undocumented' => true], ['type' => 'string'], ['type' => 'string', 'minLength' => 1]]],
            'string',
        ];
        yield 'invalid example' => [['type' => 'integer', 'examples' => ['x']], 1];
        yield 'nullable enum' => [['type' => ['string', 'null'], 'enum' => [null, 'x']], 'x'];
        yield 'null enum value' => [['type' => ['string', 'null'], 'enum' => ['x', null], 'pattern' => '^y$'], null];
        yield 'list of nothing' => [['type' => 'array', 'items' => false], []];
        yield 'true conjunct' => [['allOf' => [true, ['type' => 'integer']]], 1];
        yield 'enum under a conjunct' => [['allOf' => [['enum' => ['a', 'b']], ['enum' => ['c', 'b']]]], 'b'];
        yield 'nullable type' => [['type' => ['null', 'string']], 'string'];
        yield 'null alternative' => [['oneOf' => [['type' => 'null'], ['type' => 'integer', 'minimum' => 7]]], 7];
        yield 'null when nothing else' => [['anyOf' => [['type' => 'string', 'pattern' => '^x$'], ['type' => 'null']]], null];
        yield 'format alternatives' => [['type' => 'string', 'anyOf' => [['format' => 'ipv4'], ['format' => 'ipv6']]], '192.0.2.1'];
        yield 'object or list' => [['type' => ['object', 'array'], 'properties' => ['a' => ['type' => 'string']], 'minProperties' => 2, 'minItems' => 2], ['string', 'string']];
        yield 'items conjuncts' => [['type' => 'array', 'items' => ['allOf' => [['enum' => ['x', 'y']], ['type' => 'string']]]], ['x']];
        yield 'distinct list' => [['type' => 'array', 'items' => ['enum' => ['x', 'y', 'z']], 'minItems' => 2, 'uniqueItems' => true], ['x', 'y']];
        yield 'distinct list of one' => [['type' => 'array', 'items' => ['type' => 'string'], 'uniqueItems' => true], ['string']];
    }

    /**
     * Test that each schema generates its deterministic value.
     *
     * @param  array<string, mixed>  $schema
     * @param  mixed  $expected
     * @return void
     */
    #[DataProvider('valueProvider')]
    public function testSchemaGeneratesItsValue(array $schema, mixed $expected): void
    {
        self::assertSame($expected, $this->value($schema));
    }

    /**
     * Test that a request example sends every property that has a value, and
     * never a false one.
     *
     * @return void
     */
    public function testRequestSendsEveryPropertyThatHasAValue(): void
    {
        self::assertSame(['a' => 'string', 'c' => 1], $this->value([
            'type'       => 'object',
            'properties' => ['a' => ['type' => 'string'], 'b' => false, 'c' => ['type' => 'integer'], 'd' => ['type' => 'string', 'pattern' => '^x$']],
            'required'   => ['c'],
        ]));
    }

    /**
     * Test that a request example satisfies the presence rules of its object by
     * sending every field.
     *
     * @return void
     */
    public function testRequestSatisfiesPresenceRules(): void
    {
        self::assertSame(['a' => 'string', 'b' => 'string', 'c' => 'string'], $this->value([
            'type'              => 'object',
            'properties'        => ['a' => ['type' => 'string'], 'b' => ['type' => 'string'], 'c' => ['type' => 'string']],
            'anyOf'             => [['required' => ['a']], ['required' => ['b']]],
            'dependentRequired' => ['a' => ['c']],
            'allOf'             => [['if' => ['required' => ['b']], 'then' => ['required' => ['c']]]],
        ]));
    }

    /**
     * Test that a request falls back to its required properties first up to the
     * maximum, then to the required ones alone.
     *
     * @return void
     */
    public function testRequestFallsBackWithinItsPropertyCount(): void
    {
        $properties = ['a' => ['type' => 'string'], 'b' => ['type' => 'string'], 'c' => ['type' => 'string']];

        self::assertSame(['a' => 'string', 'c' => 'string'], $this->value(['type' => 'object', 'properties' => $properties, 'required' => ['c'], 'maxProperties' => 2]));
        self::assertSame(['a' => 'string', 'b' => 'string'], $this->value(['type' => 'object', 'properties' => $properties, 'required' => ['a'], 'maxProperties' => 2]));
        self::assertSame(['c' => 'string'], $this->value(['type' => 'object', 'properties' => $properties, 'required' => ['c'], 'dependentRequired' => ['a' => ['d']]]));
        self::assertSame(
            ['a' => 'string', 'b' => 'string', 'c' => 'string', 'd' => 'string'],
            $this->value([
                'type'          => 'object',
                'properties'    => [...$properties, 'd' => ['type' => 'string'], 'e' => ['type' => 'string']],
                'required'      => ['b', 'd'],
                'maxProperties' => 4,
            ]),
        );
    }

    /**
     * Test that an object whose candidates its schema all rejects falls back to
     * its first, never to a presence alternative's value.
     *
     * @return void
     */
    public function testRejectedObjectFallsBackToItsFirstCandidate(): void
    {
        self::assertSame(['a' => 'string'], $this->value([
            'type'          => 'object',
            'properties'    => ['a' => ['type' => 'string']],
            'anyOf'         => [['required' => ['a']], ['required' => ['b']]],
            'maxProperties' => 0,
        ]));
        self::assertSame(['a' => 'string'], $this->value(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a', 'ghost']]));
    }

    /**
     * Test that an optional property its schema rejects is left out, keeping
     * the other optional properties.
     *
     * @return void
     */
    public function testRejectedOptionalPropertyIsLeftOut(): void
    {
        self::assertSame(['id' => 1, 'name' => 'string'], $this->value([
            'type'       => 'object',
            'properties' => [
                'id'   => ['type' => 'integer'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a', 'ghost']]],
                'name' => ['type' => 'string'],
            ],
            'required' => ['id'],
        ]));
    }

    /**
     * Test that unique items take distinct candidates the items accept, those
     * of a referenced schema included, and a list with too few, or whose items
     * the walk is already inside, is reported, as undocumented when its items
     * are.
     *
     * @return void
     */
    public function testUniqueItemsTakeDistinctCandidates(): void
    {
        $list = ['type' => 'array', 'minItems' => 2, 'uniqueItems' => true];

        self::assertSame(['banned', 'suspended'], $this->value(
            [...$list, 'items' => ['$ref' => '#/components/schemas/Status', 'not' => ['const' => 'active']]],
            ExampleScope::REQUEST,
            ['Status' => ['type' => 'string', 'enum' => ['active', 'banned', 'suspended']]],
        ));
        self::assertEquals(new MissingValue(self::BODY), $this->generate([...$list, 'items' => ['type' => 'string']]));
        self::assertEquals(new MissingValue(self::BODY), $this->generate($list));
        self::assertEquals(new MissingValue(self::BODY), $this->generate([...$list, 'items' => false]));
        self::assertEquals(new MissingValue('/components/schemas/Node/properties/children'), $this->generate(
            ['$ref' => '#/components/schemas/Node'],
            ExampleScope::RESPONSE,
            ['Node' => ['type' => 'object', 'properties' => ['children' => [...$list, 'items' => ['$ref' => '#/components/schemas/Node']]], 'required' => ['children']]],
        ));
        self::assertEquals(new MissingValue(self::BODY . '/items', true), $this->generate([...$list, 'items' => ['x-undocumented' => true]]));
    }

    /**
     * Test that a numbered property the generator finds no value for is
     * reported when required.
     *
     * @return void
     */
    public function testNumberedRequiredPropertyWithoutAValueIsReported(): void
    {
        self::assertEquals(
            new MissingValue(self::BODY . '/properties/0'),
            $this->generate(['type' => 'object', 'properties' => ['0' => ['type' => 'string', 'pattern' => '^x$']], 'required' => ['0']]),
        );
    }

    /**
     * Test that a response example holds the required and default properties,
     * else the required ones alone.
     *
     * @return void
     */
    public function testResponseHoldsTheRequiredAndDefaultProperties(): void
    {
        $properties = ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'bio' => ['type' => 'string']];

        self::assertSame(['id' => 1, 'name' => 'string'], $this->value(
            ['type' => 'object', 'properties' => $properties, 'required' => ['id'], 'x-default-fields' => ['id', 'name']],
            ExampleScope::RESPONSE,
        ));
        self::assertSame(['id' => 1], $this->value(['type' => 'object', 'properties' => $properties, 'required' => ['id']], ExampleScope::RESPONSE));
        self::assertSame(['id' => 1, 'name' => 'string'], $this->value(
            ['type' => 'object', 'properties' => $properties, 'x-default-fields' => ['name', 'id']],
            ExampleScope::RESPONSE,
        ));
        self::assertSame(['id' => 1], $this->value(
            ['type' => 'object', 'properties' => $properties, 'required' => ['id'], 'x-default-fields' => ['name'], 'maxProperties' => 1],
            ExampleScope::RESPONSE,
        ));
    }

    /**
     * Test that an empty object encodes as an object.
     *
     * @return void
     */
    public function testEmptyObjectEncodesAsAnObject(): void
    {
        $examples = $this->generator(['type' => 'object'])->generate(self::BODY, ExampleScope::REQUEST);

        self::assertSame('{"default":{}}', json_encode($examples, JSON_THROW_ON_ERROR));
    }

    /**
     * Test that a confirmation repeats the value of the field it confirms,
     * wherever it is declared.
     *
     * @return void
     */
    public function testConfirmationRepeatsTheFieldItConfirms(): void
    {
        self::assertSame(['password_confirmation' => 'secret', 'password' => 'secret'], $this->value([
            'type'       => 'object',
            'properties' => [
                'password_confirmation' => ['type' => 'string', 'x-confirms' => 'password'],
                'password'              => ['type' => 'string', 'examples' => ['secret']],
            ],
        ]));
    }

    /**
     * Test that a confirmation of a field the example leaves out takes its own
     * value.
     *
     * @return void
     */
    public function testConfirmationOfAnAbsentFieldTakesItsOwnValue(): void
    {
        self::assertSame(['pin_repeat' => 'string', 'code' => 'ABC', 'code_repeat' => 'ABC'], $this->value([
            'type'       => 'object',
            'properties' => [
                'pin'         => ['type' => 'string', 'pattern' => '^x$'],
                'pin_repeat'  => ['type' => 'string', 'x-confirms' => 'pin'],
                'code'        => ['type' => 'string', 'examples' => ['ABC']],
                'code_repeat' => ['type' => 'string', 'x-confirms' => 'code'],
            ],
        ]));
    }

    /**
     * Test that a confirmation its field's value does not fit is left for the
     * final validation to reject.
     *
     * @return void
     */
    public function testIncompatibleConfirmationIsLeftForValidation(): void
    {
        self::assertSame(['password' => 'secret', 'password_confirmation' => 'secret'], $this->value([
            'type'       => 'object',
            'properties' => [
                'password'              => ['type' => 'string', 'examples' => ['secret']],
                'password_confirmation' => ['type' => 'integer', 'x-confirms' => 'password'],
            ],
            'required' => ['password', 'password_confirmation'],
        ]));
    }

    /**
     * Test that an object made of a referenced base and its own properties
     * merges both, its presence conjuncts only validating.
     *
     * @return void
     */
    public function testConjunctObjectsMergeTheirProperties(): void
    {
        self::assertSame(['id' => 1, 'extra' => 'string'], $this->value(
            ['allOf' => [
                ['$ref' => '#/components/schemas/Base'],
                ['properties' => ['extra' => ['type' => 'string']], 'required' => ['extra']],
                ['anyOf' => [['required' => ['id']]]],
            ]],
            ExampleScope::RESPONSE,
            ['Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'bio' => ['type' => 'string']], 'required' => ['id']]],
        ));
    }

    /**
     * Test that a property its node and a conjunct both declare takes the
     * node's own schema.
     *
     * @return void
     */
    public function testOwnPropertyWinsOverAConjunctsOfTheSameName(): void
    {
        self::assertSame(['id' => 5], $this->value(
            ['properties' => ['id' => ['type' => 'integer', 'minimum' => 5]], 'allOf' => [['$ref' => '#/components/schemas/Base']]],
            ExampleScope::REQUEST,
            ['Base' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
        ));
    }

    /**
     * Test that a recursive schema ends at null or an empty list, and fails
     * when it cannot.
     *
     * @return void
     */
    public function testRecursionEndsAtNullOrAnEmptyList(): void
    {
        $node = ['type' => 'object', 'properties' => [
            'parent'   => ['anyOf' => [['$ref' => '#/components/schemas/Body'], ['type' => 'null']]],
            'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Body']],
        ], 'required' => ['parent', 'children']];

        self::assertSame(['parent' => null, 'children' => []], $this->value(['$ref' => '#/components/schemas/Node'], ExampleScope::RESPONSE, ['Node' => $node]));

        $missing = $this->generate(
            ['$ref' => '#/components/schemas/Node'],
            ExampleScope::RESPONSE,
            ['Node' => ['type' => 'object', 'properties' => ['self' => ['$ref' => '#/components/schemas/Node']], 'required' => ['self']]],
        );

        self::assertEquals(new MissingValue('/components/schemas/Node'), $missing);
    }

    /**
     * Test that a required value the generator finds none for is reported by
     * its pointer, and an undocumented one as undocumented, while an optional
     * one is left out.
     *
     * @return void
     */
    public function testMissingRequiredValueIsReportedByItsPointer(): void
    {
        $properties = ['code' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'], 'data' => ['x-undocumented' => true]];

        self::assertEquals(new MissingValue(self::BODY . '/properties/code'), $this->generate(['type' => 'object', 'properties' => $properties, 'required' => ['code']]));
        self::assertEquals(new MissingValue(self::BODY . '/properties/data', true), $this->generate(['type' => 'object', 'properties' => $properties, 'required' => ['data']]));
        self::assertSame([], $this->value(['type' => 'object', 'properties' => $properties]));
        self::assertSame(['data' => ['a' => 1]], $this->value(
            ['type' => 'object', 'properties' => ['data' => ['x-undocumented' => true, 'examples' => [['a' => 1]]]], 'required' => ['data']],
        ));
    }

    /**
     * Test that an undocumented item leaves its list without a value.
     *
     * @return void
     */
    public function testUndocumentedItemLeavesItsListWithoutAValue(): void
    {
        self::assertEquals(
            new MissingValue(self::BODY . '/items', true),
            $this->generate(['type' => 'array', 'items' => ['x-undocumented' => true]]),
        );
        self::assertEquals(
            new MissingValue(self::BODY . '/items'),
            $this->generate(['type' => 'array', 'items' => ['type' => 'string', 'enum' => []], 'minItems' => 1]),
        );
    }

    /**
     * Test that the first shape union yields one example per shape, named by
     * title, target title, component name, else position, each name unique.
     *
     * @return void
     */
    public function testShapeUnionYieldsOneExamplePerShape(): void
    {
        $examples = $this->generate(
            ['type' => 'object', 'properties' => ['data' => ['oneOf' => [
                ['title' => 'Signed in', 'type' => 'object', 'properties' => ['status' => ['const' => 'signed_in']], 'required' => ['status']],
                ['$ref' => '#/components/schemas/Pending'],
                ['$ref' => '#/components/schemas/Challenge'],
                ['type' => 'object', 'properties' => ['status' => ['const' => 'locked']], 'required' => ['status']],
                ['title' => 'Signed in', 'type' => 'object', 'properties' => ['status' => ['const' => 'refreshed']], 'required' => ['status']],
                ['type' => 'null'],
            ]]], 'required' => ['data']],
            ExampleScope::RESPONSE,
            [
                'Pending'   => ['title' => 'Awaiting a code', 'type' => 'object', 'properties' => ['status' => ['const' => 'pending']], 'required' => ['status']],
                'Challenge' => ['type' => 'object', 'properties' => ['status' => ['const' => 'challenge']], 'required' => ['status']],
            ],
        );

        self::assertSame([
            'Signed in'       => ['data' => ['status' => 'signed_in']],
            'Awaiting a code' => ['data' => ['status' => 'pending']],
            'Challenge'       => ['data' => ['status' => 'challenge']],
            'Alternative 4'   => ['data' => ['status' => 'locked']],
            'Signed in 2'     => ['data' => ['status' => 'refreshed']],
        ], $examples);
    }

    /**
     * Test that both pagination shapes of an index each get an example from
     * their own shape.
     *
     * @return void
     */
    public function testEachPaginationShapeGetsItsOwnExample(): void
    {
        $examples = $this->generate(['anyOf' => [
            [
                'title'      => 'Length-aware page',
                'type'       => 'object',
                'properties' => ['meta' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer']], 'required' => ['total']]],
                'required'   => ['meta'],
            ],
            ['title' => 'Cursor page', 'type' => 'object', 'properties' => ['meta' => ['type' => 'object']], 'required' => ['meta']],
        ]], ExampleScope::RESPONSE);

        self::assertSame(['Length-aware page' => ['meta' => ['total' => 1]], 'Cursor page' => ['meta' => []]], $examples);
    }

    /**
     * Test that a shape union is found through references, conjuncts and items
     * but not through an optional property.
     *
     * @return void
     */
    public function testShapeUnionIsFoundDepthFirstThroughRequiredValues(): void
    {
        $union = ['oneOf' => [['type' => 'integer'], ['type' => 'boolean']]];

        self::assertSame(['Alternative 1' => [[1]], 'Alternative 2' => [[true]]], $this->generate(
            ['$ref' => '#/components/schemas/List'],
            ExampleScope::RESPONSE,
            ['List' => ['allOf' => [['type' => 'array', 'items' => ['type' => 'array', 'items' => $union]]]]],
        ));
        self::assertSame(['default' => ['id' => 1]], $this->generate(
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'kind' => $union], 'required' => ['id']],
            ExampleScope::RESPONSE,
        ));
    }

    /**
     * Test that presence and nullable unions are not shapes, and give one
     * example.
     *
     * @return void
     */
    public function testPresenceAndNullableUnionsGiveOneExample(): void
    {
        self::assertSame(['default' => ['a' => 'string']], $this->generate(
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'anyOf' => [['required' => ['a']], ['required' => ['b']]]],
        ));
        self::assertSame(['default' => 'active'], $this->generate(
            ['anyOf' => [['$ref' => '#/components/schemas/Status'], ['type' => 'null']]],
            ExampleScope::RESPONSE,
            ['Status' => ['type' => 'string', 'enum' => ['active', 'banned']]],
        ));
    }

    /**
     * Test that a shape no alternative's value tells apart is left for the
     * final validation to reject.
     *
     * @return void
     */
    public function testAmbiguousShapeIsLeftForValidation(): void
    {
        self::assertSame(
            ['Alternative 1' => 'string', 'Alternative 2' => 'string'],
            $this->generate(['oneOf' => [['type' => 'string'], ['type' => 'string', 'minLength' => 1]]]),
        );
    }

    /**
     * Test that an alternative without a value leaves the body without one.
     *
     * @return void
     */
    public function testShapeWithoutAValueLeavesTheBodyWithoutOne(): void
    {
        self::assertEquals(
            new MissingValue(self::BODY . '/oneOf/1', true),
            $this->generate(['oneOf' => [['type' => 'integer'], ['type' => 'object', 'x-undocumented' => true]]]),
        );
    }

    /**
     * Generate the default example of a body schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope  $scope
     * @param  array<string, mixed>  $components
     * @return mixed
     */
    private function value(array $schema, ExampleScope $scope = ExampleScope::REQUEST, array $components = []): mixed
    {
        $examples = $this->generate($schema, $scope, $components);

        self::assertIsArray($examples);

        return $examples['default'];
    }

    /**
     * Generate the examples of a body schema, decoded as written.
     *
     * @param  array<string, mixed>  $schema
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope  $scope
     * @param  array<string, mixed>  $components
     * @return mixed
     */
    private function generate(array $schema, ExampleScope $scope = ExampleScope::REQUEST, array $components = []): mixed
    {
        $examples = $this->generator($schema, $components)->generate(self::BODY, $scope);

        return $examples instanceof MissingValue ? $examples : json_decode(json_encode($examples, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Build a generator over a document holding the body schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @return \SineMacula\ApiToolkit\OpenApi\Examples\ExampleGenerator
     */
    private function generator(array $schema, array $components = []): ExampleGenerator
    {
        return new ExampleGenerator(SchemaDocument::of(['components' => ['schemas' => ['Body' => $schema, ...$components]]]));
    }
}
