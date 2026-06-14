<?php

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;

/**
 * Tests for the QueryParameterBuilder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryParameterBuilder::class)]
class QueryParameterBuilderTest extends TestCase
{
    /** @var array<int, string> The twelve registered operator tokens */
    private const array OPERATOR_TOKENS = [
        '$eq', '$neq', '$gt', '$lt', '$ge', '$le',
        '$like', '$in', '$between', '$contains', '$null', '$notNull',
    ];

    /** @var array<int, string> The four structural operators */
    private const array STRUCTURAL_OPERATORS = ['$and', '$or', '$has', '$hasnt'];

    /**
     * Test that the full shared query-parameter set is emitted under its
     * canonical component names.
     *
     * @return void
     */
    public function testEmitsTheFullSharedParameterSet(): void
    {
        $parameters = $this->makeBuilder()->build();

        foreach (['Fields', 'Filter', 'Order', 'Limit', 'Page', 'Cursor', 'Counts'] as $name) {
            static::assertArrayHasKey($name, $parameters);
        }

        static::assertCount(7, $parameters);
    }

    /**
     * Test that every parameter is a query parameter carrying its conventional
     * request name.
     *
     * @return void
     */
    public function testParametersAreQueryParametersWithConventionalNames(): void
    {
        $parameters = $this->makeBuilder()->build();

        static::assertSame('query', $parameters['Fields']['in']);
        static::assertSame('fields', $parameters['Fields']['name']);
        static::assertSame('filter', $parameters['Filter']['name']);
        static::assertSame('order', $parameters['Order']['name']);
        static::assertSame('limit', $parameters['Limit']['name']);
        static::assertSame('page', $parameters['Page']['name']);
        static::assertSame('cursor', $parameters['Cursor']['name']);
        static::assertSame('counts', $parameters['Counts']['name']);
    }

    /**
     * Test that the filter parameter documents every registered operator token.
     *
     * @return void
     */
    public function testFilterParameterCoversEveryRegisteredOperator(): void
    {
        $operators = $this->makeBuilder()->build()['Filter']['schema']['x-operators'];

        foreach (self::OPERATOR_TOKENS as $token) {
            static::assertContains($token, $operators);
        }
    }

    /**
     * Test that the filter parameter documents every structural operator.
     *
     * @return void
     */
    public function testFilterParameterCoversEveryStructuralOperator(): void
    {
        $operators = $this->makeBuilder()->build()['Filter']['schema']['x-operators'];

        foreach (self::STRUCTURAL_OPERATORS as $token) {
            static::assertContains($token, $operators);
        }
    }

    /**
     * Test that the filter parameter enumerates exactly the 12 registered plus
     * 4 structural operators -- the full 12+4 vocabulary.
     *
     * @return void
     */
    public function testFilterParameterEnumeratesTheFullTwelvePlusFourVocabulary(): void
    {
        $operators = $this->makeBuilder()->build()['Filter']['schema']['x-operators'];

        static::assertCount(16, $operators);
    }

    /**
     * Test that the filter description names the operators so consumers learn
     * the grammar at the pattern level.
     *
     * @return void
     */
    public function testFilterDescriptionNamesTheOperatorGrammar(): void
    {
        $filter = $this->makeBuilder()->build()['Filter'];

        static::assertStringContainsString('$eq', $filter['description']);
        static::assertStringContainsString('$and', $filter['description']);
    }

    /**
     * Test that the filter parameter does not declare a closed per-resource
     * field allow-list, so it never over-claims precision.
     *
     * @return void
     */
    public function testFilterParameterDeclaresNoPerResourceAllowList(): void
    {
        $schema = $this->makeBuilder()->build()['Filter']['schema'];

        static::assertTrue($schema['additionalProperties']);
        static::assertArrayNotHasKey('enum', $schema);
        static::assertArrayNotHasKey('properties', $schema);
    }

    /**
     * Test that the operator vocabulary reflects registry overrides rather than
     * a hard-coded list.
     *
     * @return void
     */
    public function testOperatorVocabularyIsRegistryDriven(): void
    {
        $catalogue = static::createStub(MetadataCatalogue::class);
        $catalogue->method('getOperatorTokens')->willReturn(['$custom']);
        $catalogue->method('getStructuralOperators')->willReturn(['$and']);

        $operators = (new QueryParameterBuilder($catalogue))->build()['Filter']['schema']['x-operators'];

        static::assertSame(['$custom', '$and'], $operators);
    }

    /**
     * Test that the pagination parameters carry sane integer constraints.
     *
     * @return void
     */
    public function testPaginationParametersAreConstrainedIntegers(): void
    {
        $parameters = $this->makeBuilder()->build();

        static::assertSame('integer', $parameters['Limit']['schema']['type']);
        static::assertSame(1, $parameters['Limit']['schema']['minimum']);
        static::assertSame('integer', $parameters['Page']['schema']['type']);
        static::assertSame('string', $parameters['Cursor']['schema']['type']);
    }

    /**
     * Build a QueryParameterBuilder backed by a stub returning the default
     * 12+4 operator vocabulary.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\QueryParameterBuilder
     */
    private function makeBuilder(): QueryParameterBuilder
    {
        $catalogue = static::createStub(MetadataCatalogue::class);
        $catalogue->method('getOperatorTokens')->willReturn(self::OPERATOR_TOKENS);
        $catalogue->method('getStructuralOperators')->willReturn(self::STRUCTURAL_OPERATORS);

        return new QueryParameterBuilder($catalogue);
    }
}
