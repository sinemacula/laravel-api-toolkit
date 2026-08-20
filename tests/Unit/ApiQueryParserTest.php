<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\TestCase;

/**
 * Tests for the ApiQueryParser.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1448")
 *
 * @internal
 */
#[CoversClass(ApiQueryParser::class)]
final class ApiQueryParserTest extends TestCase
{
    use InteractsWithNonPublicMembers;

    /** @var string */
    private const string TEST_URL = '/test';

    /** @var string */
    private const string FIELDS_NAME_EMAIL = 'name,email';

    /** @var \SineMacula\ApiToolkit\ApiQueryParser */
    private ApiQueryParser $parser;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ApiQueryParser;
    }

    /**
     * Test that getFields returns null when no fields are set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFieldsReturnsNullWhenNoFieldsSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertNull($this->parser->getFields());
    }

    /**
     * Test that getFields parses a comma-separated string.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFieldsParsesCommaSeparatedString(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => 'first_name,last_name,email']);
        $this->parser->parse($request);

        self::assertSame(['first_name', 'last_name', 'email'], $this->parser->getFields());
    }

    /**
     * Test that getFields trims field values.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFieldsTrimsValues(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ' first_name , last_name ']);
        $this->parser->parse($request);

        self::assertSame(['first_name', 'last_name'], $this->parser->getFields());
    }

    /**
     * Test that getFields with resource key returns specific resource fields.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFieldsWithResourceKeyReturnsSpecificFields(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => [
                'user' => self::FIELDS_NAME_EMAIL,
                'post' => 'title,body',
            ],
        ]);
        $this->parser->parse($request);

        self::assertSame(['name', 'email'], $this->parser->getFields('user'));
        self::assertSame(['title', 'body'], $this->parser->getFields('post'));
    }

    /**
     * Test that getFields with unknown resource key returns null.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFieldsWithUnknownResourceReturnsNull(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['user' => self::FIELDS_NAME_EMAIL],
        ]);
        $this->parser->parse($request);

        self::assertNull($this->parser->getFields('unknown'));
    }

    /**
     * Test that getCounts returns null when no counts are set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetCountsReturnsNullWhenNoCountsSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertNull($this->parser->getCounts());
    }

    /**
     * Test that getCounts parses comma-separated counts.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetCountsParsesCommaSeparatedCounts(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'counts' => ['user' => 'posts,comments'],
        ]);
        $this->parser->parse($request);

        self::assertSame(['posts', 'comments'], $this->parser->getCounts('user'));
    }

    /**
     * Test that getSums parses aggregation format.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetSumsParsesAggregationFormat(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => [
                'account' => [
                    'transaction' => 'amount,fee',
                ],
            ],
        ]);
        $this->parser->parse($request);

        $result = $this->parser->getSums('account');

        self::assertIsArray($result);
        self::assertArrayHasKey('transaction', $result);
        self::assertSame(['amount', 'fee'], $result['transaction']);
    }

    /**
     * Test that getSums returns null when no sums are set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetSumsReturnsNullWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertNull($this->parser->getSums());
    }

    /**
     * Test that getAverages parses aggregation format.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetAveragesParsesAggregationFormat(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'averages' => [
                'account' => [
                    'transaction' => 'amount',
                ],
            ],
        ]);
        $this->parser->parse($request);

        $result = $this->parser->getAverages('account');

        self::assertIsArray($result);
        self::assertArrayHasKey('transaction', $result);
        self::assertSame(['amount'], $result['transaction']);
    }

    /**
     * Test that getFilters returns an empty array when no filters are set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFiltersReturnsEmptyArrayWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertSame([], $this->parser->getFilters());
    }

    /**
     * Test that getFilters parses a JSON filter string.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFiltersParsesJsonFilterString(): void
    {
        $filters = json_encode(['status' => 'active', 'role' => 'admin']);
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => $filters]);
        $this->parser->parse($request);

        $result = $this->parser->getFilters();

        self::assertSame(['status' => 'active', 'role' => 'admin'], $result);
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
     * Test that parsing rejects a valid-JSON but non-associative filter value
     * rather than reducing it to an empty set, which would answer with the
     * unfiltered table.
     *
     * @param  string  $filters
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('nonAssociativeFilterProvider')]
    public function testParseRejectsNonAssociativeJsonFilters(string $filters): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => $filters]);

        try {
            $this->parser->parse($request);
            self::fail('Expected a ValidationException for the filters parameter.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters' => ['The filters parameter must be a JSON object.']], $exception->errors());
        }
    }

    /**
     * Test that getFields tolerates a list-form parameter without a resource
     * key, returning a safe result instead of raising a type error while
     * trimming the nested value.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFieldsToleratesListFormParameter(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ['name', 'email']]);
        $this->parser->parse($request);

        self::assertSame([], $this->parser->getFields());
    }

    /**
     * Test that getCounts tolerates a list-form parameter without a resource
     * key, returning a safe result instead of raising a type error while
     * trimming the nested value.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetCountsToleratesListFormParameter(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['counts' => ['posts', 'comments']]);
        $this->parser->parse($request);

        self::assertSame([], $this->parser->getCounts());
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
        yield 'random order' => ['random', ['random' => 'asc']];
        yield 'trailing empty segment' => ['name,', ['name' => 'asc']];
        yield 'leading empty segment' => [',name', ['name' => 'asc']];
        yield 'empty column with direction' => [':desc', []];
    }

    /**
     * Test that getOrder parses order with direction.
     *
     * @param  string  $orderString
     * @param  array<string, string>  $expected
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('orderProvider')]
    public function testGetOrderParsesOrderWithDirection(string $orderString, array $expected): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['order' => $orderString]);
        $this->parser->parse($request);

        self::assertSame($expected, $this->parser->getOrder());
    }

    /**
     * Test that getOrder returns an empty array when no order is set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetOrderReturnsEmptyArrayWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertSame([], $this->parser->getOrder());
    }

    /**
     * Test that getLimit returns a positive integer.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetLimitReturnsPositiveInteger(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => '25']);
        $this->parser->parse($request);

        self::assertSame(25, $this->parser->getLimit());
    }

    /**
     * Test that getLimit clamps a request above the configured maximum to that
     * ceiling, so an unbounded page size cannot exhaust memory.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetLimitClampsToConfiguredMaximum(): void
    {
        config()->set('api-toolkit.parser.max_limit', 100);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => '100000']);
        $this->parser->parse($request);

        self::assertSame(100, $this->parser->getLimit());
    }

    /**
     * Test that a numeric-string maximum is coerced to an integer before
     * clamping, so the returned limit is a true int rather than the raw
     * configured string.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetLimitCoercesNumericStringMaximumToInteger(): void
    {
        config()->set('api-toolkit.parser.max_limit', '100');

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => '100000']);
        $this->parser->parse($request);

        self::assertSame(100, $this->parser->getLimit());
    }

    /**
     * Test that getLimit leaves the requested value untouched when the maximum
     * ceiling is disabled (zero).
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetLimitIsNotClampedWhenMaximumDisabled(): void
    {
        config()->set('api-toolkit.parser.max_limit', 0);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => '100000']);
        $this->parser->parse($request);

        self::assertSame(100000, $this->parser->getLimit());
    }

    /**
     * Test that a non-numeric maximum configuration disables clamping rather
     * than capping the limit to an unintended value.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetLimitTreatsNonNumericMaximumAsDisabled(): void
    {
        config()->set('api-toolkit.parser.max_limit', 'not-a-number');

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => '100000']);
        $this->parser->parse($request);

        self::assertSame(100000, $this->parser->getLimit());
    }

    /**
     * Test that getLimit returns null for zero.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetLimitReturnsNullForZero(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertNull($this->parser->getLimit());
    }

    /**
     * Test that getPage returns a positive integer.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetPageReturnsPositiveInteger(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['page' => '5']);
        $this->parser->parse($request);

        self::assertSame(5, $this->parser->getPage());
    }

    /**
     * Test that getPage returns 1 when not set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetPageReturnsOneWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertSame(1, $this->parser->getPage());
    }

    /**
     * Test that getCursor returns a cursor string.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetCursorReturnsCursorString(): void
    {
        $cursor  = 'eyJpZCI6MTAwfQ==';
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['cursor' => $cursor]);
        $this->parser->parse($request);

        self::assertSame($cursor, $this->parser->getCursor());
    }

    /**
     * Test that getCursor returns an empty string when not set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetCursorReturnsEmptyStringWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertSame('', $this->parser->getCursor());
    }

    /**
     * Test that getCursor coerces a non-string scalar into its string form
     * rather than returning the raw scalar.
     *
     * @return void
     */
    public function testGetCursorCastsNonStringScalarToString(): void
    {
        $this->setProperty($this->parser, 'parameters', ['cursor' => 100]);

        self::assertSame('100', $this->parser->getCursor());
    }

    /**
     * Test that validation fails for invalid page parameter.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsForInvalidPage(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['page' => 'not-a-number']);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails for invalid limit parameter.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsForInvalidLimit(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => 'abc']);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails for invalid JSON filters.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsForInvalidJsonFilters(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => 'not-valid-json{']);
        $this->parser->parse($request);
    }

    /**
     * Test parsing multiple parameters simultaneously.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testParsingMultipleParametersSimultaneously(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields'  => self::FIELDS_NAME_EMAIL,
            'order'   => 'name:asc',
            'page'    => '2',
            'limit'   => '10',
            'filters' => json_encode(['active' => true]),
        ]);

        $this->parser->parse($request);

        self::assertSame(['name', 'email'], $this->parser->getFields());
        self::assertSame(['name' => 'asc'], $this->parser->getOrder());
        self::assertSame(2, $this->parser->getPage());
        self::assertSame(10, $this->parser->getLimit());
        self::assertSame(['active' => true], $this->parser->getFilters());
    }

    /**
     * Test that normalizeFields returns an array value unchanged.
     *
     * This path is only reachable through direct method invocation because the
     * parser's validation rules reject non-string aggregation field values
     * before they can reach the parsing logic.
     *
     * @return void
     */
    public function testNormalizeFieldsPreservesArrayInput(): void
    {
        $result = $this->invokeMethod(new QueryParameterExtractor, 'normalizeFields', ['amount', 'fee']);

        self::assertSame(['amount', 'fee'], $result);
    }

    /**
     * Test that normalizeFields wraps non-string non-array input in an array.
     *
     * @return void
     */
    public function testNormalizeFieldsWrapsOtherInputInArray(): void
    {
        $result = $this->invokeMethod(new QueryParameterExtractor, 'normalizeFields', 42);

        self::assertSame([42], $result);
    }

    /**
     * Test that parseAggregations skips non-array relation entries.
     *
     * @return void
     */
    public function testParseAggregationsSkipsNonArrayRelations(): void
    {
        $result = $this->invokeMethod(new QueryParameterExtractor, 'parseAggregations', [
            'valid'   => ['fields' => 'amount'],
            'invalid' => 'not_an_array',
        ]);

        self::assertArrayHasKey('valid', $result);
        self::assertArrayNotHasKey('invalid', $result);
    }

    /**
     * Test that parseAggregations continues past a non-array entry and still
     * parses subsequent valid entries.
     *
     * @return void
     */
    public function testParseAggregationsContinuesPastNonArrayRelations(): void
    {
        $result = $this->invokeMethod(new QueryParameterExtractor, 'parseAggregations', [
            'invalid' => 'not_an_array',
            'valid'   => ['fields' => 'amount'],
        ]);

        self::assertArrayNotHasKey('invalid', $result);
        self::assertArrayHasKey('valid', $result);
    }

    /**
     * Test that getFields trims values stored directly in the parameter state.
     *
     * @return void
     */
    public function testGetFieldsTrimsStoredParameterValues(): void
    {
        $this->setProperty($this->parser, 'parameters', ['fields' => [' first_name ', ' last_name ']]);

        self::assertSame(['first_name', 'last_name'], $this->parser->getFields());
    }

    /**
     * Test that getCounts trims values stored directly in the parameter state.
     *
     * @return void
     */
    public function testGetCountsTrimsStoredParameterValues(): void
    {
        $this->setProperty($this->parser, 'parameters', ['counts' => [' posts ', ' comments ']]);

        self::assertSame(['posts', 'comments'], $this->parser->getCounts());
    }

    /**
     * Test that getLimit truncates fractional values below one and returns
     * null.
     *
     * @return void
     */
    public function testGetLimitReturnsNullForFractionalValueBelowOne(): void
    {
        $this->setProperty($this->parser, 'parameters', ['limit' => '0.5']);

        self::assertNull($this->parser->getLimit());
    }

    /**
     * Test that getPage truncates fractional values below one and returns the
     * first page.
     *
     * @return void
     */
    public function testGetPageReturnsOneForFractionalValueBelowOne(): void
    {
        $this->setProperty($this->parser, 'parameters', ['page' => '0.5']);

        self::assertSame(1, $this->parser->getPage());
    }

    /**
     * Test that parse trims surrounding whitespace from the page and limit
     * parameters.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testParseTrimsPageAndLimitParameters(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['page' => ' 2 ', 'limit' => ' 10 ']);
        $this->parser->parse($request);

        $parameters = $this->getProperty($this->parser, 'parameters');

        self::assertSame('2', $parameters['page']);
        self::assertSame('10', $parameters['limit']);
    }

    /**
     * Test that getSums trims whitespace around aggregation field values.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetSumsTrimsWhitespaceAroundFields(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => [
                'account' => [
                    'transaction' => ' amount , fee ',
                ],
            ],
        ]);
        $this->parser->parse($request);

        $result = $this->parser->getSums('account');

        self::assertIsArray($result);
        self::assertSame(['amount', 'fee'], $result['transaction']);
    }

    /**
     * Test that getSums parses every resource supplied in the query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetSumsParsesAllResources(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => [
                'account' => ['transaction' => 'amount'],
                'user'    => ['posts' => 'votes'],
            ],
        ]);
        $this->parser->parse($request);

        self::assertSame(['transaction' => ['amount']], $this->parser->getSums('account'));
        self::assertSame(['posts' => ['votes']], $this->parser->getSums('user'));
    }

    /**
     * Test that getOrder returns an empty array for an empty order string.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetOrderReturnsEmptyArrayForEmptyOrderString(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['order' => '']);
        $this->parser->parse($request);

        self::assertSame([], $this->parser->getOrder());
    }

    /**
     * Test that getOrder treats everything after the first colon as the
     * direction.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetOrderTreatsEverythingAfterFirstColonAsDirection(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['order' => 'name:desc:extra']);
        $this->parser->parse($request);

        self::assertSame(['name' => 'desc:extra'], $this->parser->getOrder());
    }

    /**
     * Test that validation fails when a fields resource value is an array.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsWhenFieldsResourceValueIsArray(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['user' => ['name', 'email']],
        ]);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails when a counts resource value is an array.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsWhenCountsResourceValueIsArray(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'counts' => ['user' => ['posts', 'comments']],
        ]);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails when a sums resource value is not an array.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsWhenSumsResourceValueIsNotArray(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'sums' => ['account' => 'amount'],
        ]);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails when an averages resource value is not an
     * array.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsWhenAveragesResourceValueIsNotArray(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'averages' => ['account' => 'amount'],
        ]);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails when the fields parameter is an integer.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsWhenFieldsIsInteger(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => 123]);
        $this->parser->parse($request);
    }

    /**
     * Test that reset clears all previously parsed parameters.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testResetClearsAllParsedParameters(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => self::FIELDS_NAME_EMAIL]);
        $this->parser->parse($request);

        self::assertSame(['name', 'email'], $this->parser->getFields());

        $this->parser->reset();

        self::assertNull($this->parser->getFields());
    }
}
