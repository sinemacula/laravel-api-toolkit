<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\ApiToolkit\Search\SearchTerm;
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
     * Test that extract returns an empty array when no parameters are supplied.
     *
     * @return void
     */
    public function testExtractReturnsEmptyArrayWhenNoParametersSupplied(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());

        self::assertSame([], $this->extractor->extract($request));
    }

    /**
     * Test that extract only includes the keys present on the request.
     *
     * @return void
     */
    public function testExtractOnlyIncludesPresentKeys(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => 'name']);

        self::assertSame(['fields' => ['name']], $this->extractor->extract($request));
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

        self::assertSame('2', $parameters['page']);
        self::assertSame('10', $parameters['limit']);
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

        self::assertSame($cursor, $parameters['cursor']);
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

        self::assertSame(['first_name', 'last_name'], $parameters['fields']);
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

        self::assertSame([
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

        self::assertSame(['posts', 'comments'], $parameters['counts']);
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

        self::assertSame(['user' => ['posts', 'comments']], $parameters['counts']);
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

        self::assertSame(['account' => ['transaction' => ['amount', 'fee']]], $parameters['sums']);
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

        self::assertSame(['account' => ['transaction' => ['amount']]], $parameters['averages']);
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

        self::assertSame(['account' => ['transaction' => ['amount']]], $parameters['sums']);
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

        self::assertSame(['account' => ['transaction' => ['amount', 'fee']]], $parameters['sums']);
    }

    /**
     * Test that extract wraps scalar non-string aggregation field values in an
     * array.
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

        self::assertSame(['account' => ['transaction' => [42]]], $parameters['sums']);
    }

    /**
     * Test that extract retains every aggregation resource rather than
     * truncating the parsed set.
     *
     * @return void
     */
    public function testExtractParsesEveryAggregationResource(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => [
                'account' => ['transaction' => 'amount'],
                'user'    => ['posts' => 'votes'],
            ],
        ]);

        $parameters = $this->extractor->extract($request);

        self::assertSame([
            'account' => ['transaction' => ['amount']],
            'user'    => ['posts' => ['votes']],
        ], $parameters['sums']);
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

        self::assertSame(['status' => 'active', 'role' => 'admin'], $parameters['filters']);
    }

    /**
     * Test that extract rejects an undecodable filters document rather than
     * dropping the filter and answering with the unfiltered set.
     *
     * @return void
     */
    public function testExtractRejectsUndecodableFilters(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => 'not-valid-json{']);

        $this->assertRejectsFilters($request);
    }

    /**
     * Test that extract rejects a filters document nested beyond the decoder
     * depth limit, which decodes to nothing at all.
     *
     * @return void
     */
    public function testExtractRejectsFiltersNestedBeyondTheDecoderDepthLimit(): void
    {
        $filters = str_repeat('{"a":', 512) . '1' . str_repeat('}', 512);
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => $filters]);

        $this->assertRejectsFilters($request);
    }

    /**
     * Test that extract accepts a filters document nested to exactly the
     * decoder depth limit, so the rejection lands on the breach alone.
     *
     * @return void
     */
    public function testExtractAcceptsFiltersNestedToTheDecoderDepthLimit(): void
    {
        $filters = str_repeat('{"a":', 511) . '1' . str_repeat('}', 511);
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => $filters]);

        $parameters = $this->extractor->extract($request);

        self::assertArrayHasKey('a', $parameters['filters']);
    }

    /**
     * Provide filter values that are valid JSON but not a filter map.
     *
     * @return iterable<string, array{string}>
     */
    public static function nonAssociativeFilterProvider(): iterable
    {
        yield 'integer scalar' => ['123'];
        yield 'boolean scalar' => ['true'];
        yield 'false scalar' => ['false'];
        yield 'zero scalar' => ['0'];
        yield 'quoted string scalar' => ['"x"'];
        yield 'numeric-keyed list' => ['[1,2,3]'];
        yield 'list of objects' => ['[{"a":1}]'];
    }

    /**
     * Test that extract rejects a valid-JSON but non-associative filter value
     * rather than coercing it to an empty set, which would drop the filter.
     *
     * @param  string  $filters
     * @return void
     */
    #[DataProvider('nonAssociativeFilterProvider')]
    public function testExtractRejectsNonAssociativeFilters(string $filters): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => $filters]);

        $this->assertRejectsFilters($request);
    }

    /**
     * Test that an empty JSON object is accepted, so a filter-free document is
     * not mistaken for a dropped filter.
     *
     * @return void
     */
    public function testExtractAcceptsAnEmptyFilterDocument(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => '{}']);

        $parameters = $this->extractor->extract($request);

        self::assertSame([], $parameters['filters']);
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
        yield 'trailing empty segment' => ['name,', ['name' => 'asc']];
        yield 'leading empty segment' => [',name', ['name' => 'asc']];
        yield 'empty column with direction' => [':desc', []];
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

        self::assertSame($expected, $parameters['order']);
    }

    /**
     * Test that an empty order segment never produces an empty-string column
     * key, which would emit a column-less clause into the generated SQL.
     *
     * @return void
     */
    public function testExtractDropsEmptyOrderColumns(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['order' => ',name,:desc,']);

        $parameters = $this->extractor->extract($request);

        self::assertSame(['name' => 'asc'], $parameters['order']);
        self::assertArrayNotHasKey('', $parameters['order']);
    }

    /**
     * Test that a search term is normalised into a parsed term rather than left
     * as the raw string the client sent.
     *
     * @return void
     */
    public function testExtractParsesTheSearchTerm(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['search' => '  john   smith  ']);

        $parameters = $this->extractor->extract($request);

        self::assertInstanceOf(SearchTerm::class, $parameters['search']);
        self::assertSame('john smith', $parameters['search']->value());
    }

    /**
     * Test that a search term outside its bounds is rejected while it is
     * parsed, rather than being trimmed into a term the client never sent.
     *
     * @return void
     */
    public function testExtractRejectsASearchTermBelowTheMinimumLength(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['search' => 'sm']);

        try {
            $this->extractor->extract($request);
            self::fail('Expected a ValidationException for the search parameter.');
        } catch (ValidationException $exception) {
            self::assertSame(['search' => ['The search term must be at least 3 characters.']], $exception->errors());
        }
    }

    /**
     * Test that a search parameter of the wrong shape is rejected rather than
     * coerced into a term.
     *
     * @return void
     */
    public function testExtractRejectsANonStringSearchParameter(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['search' => ['smith']]);

        $this->expectException(ValidationException::class);

        $this->extractor->extract($request);
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

        self::assertSame(['name', 'email'], $parameters['fields']);
        self::assertSame(['name' => 'asc'], $parameters['order']);
        self::assertSame('2', $parameters['page']);
        self::assertSame('10', $parameters['limit']);
        self::assertSame(['active' => true], $parameters['filters']);
    }

    /**
     * Assert that extracting the given request rejects the filters parameter
     * with a validation error naming it.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    private function assertRejectsFilters(Request $request): void
    {
        try {
            $this->extractor->extract($request);
            self::fail('Expected a ValidationException for the filters parameter.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters' => ['The filters parameter must be a JSON object.']], $exception->errors());
        }
    }
}
