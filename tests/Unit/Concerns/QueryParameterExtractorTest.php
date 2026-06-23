<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\Http\Enums\HttpMethod;
use Tests\TestCase;

/**
 * Tests for the QueryParameterExtractor concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryParameterExtractor::class)]
final class QueryParameterExtractorTest extends TestCase
{
    /** @var string */
    private const string TEST_URL = '/test';

    /** @var \SineMacula\ApiToolkit\Concerns\QueryParameterExtractor */
    private QueryParameterExtractor $extractor;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new QueryParameterExtractor;
    }

    /**
     * Test that extract returns an empty array when no parameters are
     * supplied.
     *
     * @return void
     */
    public function testExtractReturnsEmptyArrayWhenNoParametersSupplied(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());

        static::assertSame([], $this->extractor->extract($request));
    }

    /**
     * Test that extract only includes the keys present on the request.
     *
     * @return void
     */
    public function testExtractOnlyIncludesPresentKeys(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => 'name']);

        static::assertSame(['fields' => ['name']], $this->extractor->extract($request));
    }

    /**
     * Test that extract trims the page and limit parameters.
     *
     * @return void
     */
    public function testExtractTrimsPageAndLimitParameters(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['page' => ' 2 ', 'limit' => ' 10 ']);

        $parameters = $this->extractor->extract($request);

        static::assertSame('2', $parameters['page']);
        static::assertSame('10', $parameters['limit']);
    }

    /**
     * Test that extract passes the cursor through unchanged.
     *
     * @return void
     */
    public function testExtractPassesCursorThroughUnchanged(): void
    {
        $cursor  = 'eyJpZCI6MTAwfQ==';
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['cursor' => $cursor]);

        $parameters = $this->extractor->extract($request);

        static::assertSame($cursor, $parameters['cursor']);
    }

    /**
     * Test that extract splits and trims a comma-separated fields string.
     *
     * @return void
     */
    public function testExtractSplitsAndTrimsFieldsString(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ' first_name , last_name ']);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['first_name', 'last_name'], $parameters['fields']);
    }

    /**
     * Test that extract parses a fields array per resource.
     *
     * @return void
     */
    public function testExtractParsesFieldsArrayPerResource(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => [
                'user' => 'name,email',
                'post' => 'title,body',
            ],
        ]);

        $parameters = $this->extractor->extract($request);

        static::assertSame([
            'user' => ['name', 'email'],
            'post' => ['title', 'body'],
        ], $parameters['fields']);
    }

    /**
     * Test that extract splits and trims a comma-separated counts string.
     *
     * @return void
     */
    public function testExtractSplitsAndTrimsCountsString(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['counts' => ' posts , comments ']);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['posts', 'comments'], $parameters['counts']);
    }

    /**
     * Test that extract parses a counts array per resource.
     *
     * @return void
     */
    public function testExtractParsesCountsArrayPerResource(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'counts' => ['user' => 'posts,comments'],
        ]);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['user' => ['posts', 'comments']], $parameters['counts']);
    }

    /**
     * Test that extract splits and trims sum aggregation field strings.
     *
     * @return void
     */
    public function testExtractSplitsAndTrimsSumAggregationFields(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => [
                'account' => [
                    'transaction' => ' amount , fee ',
                ],
            ],
        ]);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['account' => ['transaction' => ['amount', 'fee']]], $parameters['sums']);
    }

    /**
     * Test that extract parses average aggregation fields.
     *
     * @return void
     */
    public function testExtractParsesAverageAggregationFields(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'averages' => [
                'account' => [
                    'transaction' => 'amount',
                ],
            ],
        ]);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['account' => ['transaction' => ['amount']]], $parameters['averages']);
    }

    /**
     * Test that extract skips non-array aggregation relations and continues
     * parsing subsequent valid entries.
     *
     * @return void
     */
    public function testExtractSkipsNonArrayAggregationRelations(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => [
                'invalid' => 'not_an_array',
                'account' => ['transaction' => 'amount'],
            ],
        ]);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['account' => ['transaction' => ['amount']]], $parameters['sums']);
    }

    /**
     * Test that extract preserves aggregation field values that are already
     * arrays.
     *
     * @return void
     */
    public function testExtractPreservesArrayAggregationFields(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => [
                'account' => [
                    'transaction' => ['amount', 'fee'],
                ],
            ],
        ]);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['account' => ['transaction' => ['amount', 'fee']]], $parameters['sums']);
    }

    /**
     * Test that extract wraps scalar non-string aggregation field values in
     * an array.
     *
     * @return void
     */
    public function testExtractWrapsScalarAggregationFieldsInArray(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => [
                'account' => [
                    'transaction' => 42,
                ],
            ],
        ]);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['account' => ['transaction' => [42]]], $parameters['sums']);
    }

    /**
     * Test that extract decodes a JSON filter string.
     *
     * @return void
     */
    public function testExtractDecodesJsonFilters(): void
    {
        $filters = json_encode(['status' => 'active', 'role' => 'admin']);
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => $filters]);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['status' => 'active', 'role' => 'admin'], $parameters['filters']);
    }

    /**
     * Test that extract returns an empty array for undecodable filters.
     *
     * @return void
     */
    public function testExtractReturnsEmptyArrayForUndecodableFilters(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => 'not-valid-json{']);

        $parameters = $this->extractor->extract($request);

        static::assertSame([], $parameters['filters']);
    }

    /**
     * Provide order string parsing scenarios.
     *
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function orderProvider(): iterable
    {
        yield 'single field ascending' => ['name:asc', ['name' => 'asc']];
        yield 'single field descending' => ['name:desc', ['name' => 'desc']];
        yield 'default ascending direction' => ['name', ['name' => 'asc']];
        yield 'multiple fields' => ['name:asc,created_at:desc', ['name' => 'asc', 'created_at' => 'desc']];
        yield 'mixed directions' => ['first_name,last_name:desc', ['first_name' => 'asc', 'last_name' => 'desc']];
        yield 'direction containing colons' => ['name:desc:extra', ['name' => 'desc:extra']];
        yield 'empty order string' => ['', []];
        yield 'order string of empty values' => [',,', []];
    }

    /**
     * Test that extract parses order strings into column and direction pairs.
     *
     * @param  string  $orderString
     * @param  array<string, string>  $expected
     * @return void
     */
    #[DataProvider('orderProvider')]
    public function testExtractParsesOrderStrings(string $orderString, array $expected): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['order' => $orderString]);

        $parameters = $this->extractor->extract($request);

        static::assertSame($expected, $parameters['order']);
    }

    /**
     * Test that extract parses multiple parameters simultaneously.
     *
     * @return void
     */
    public function testExtractParsesMultipleParametersSimultaneously(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields'  => 'name,email',
            'order'   => 'name:asc',
            'page'    => '2',
            'limit'   => '10',
            'filters' => json_encode(['active' => true]),
        ]);

        $parameters = $this->extractor->extract($request);

        static::assertSame(['name', 'email'], $parameters['fields']);
        static::assertSame(['name' => 'asc'], $parameters['order']);
        static::assertSame('2', $parameters['page']);
        static::assertSame('10', $parameters['limit']);
        static::assertSame(['active' => true], $parameters['filters']);
    }
}
