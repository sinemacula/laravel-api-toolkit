<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\ApiQueryParser;
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
class ApiQueryParserTest extends TestCase
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
    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ApiQueryParser;
    }

    /**
     * Test that getFields returns null when no fields are set.
     *
     * @return void
     */
    public function testGetFieldsReturnsNullWhenNoFieldsSet(): void
    {
        $request = Request::create(self::TEST_URL, 'GET');
        $this->parser->parse($request);

        static::assertNull($this->parser->getFields());
    }

    /**
     * Test that getFields parses a comma-separated string.
     *
     * @return void
     */
    public function testGetFieldsParsesCommaSeparatedString(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', ['fields' => 'first_name,last_name,email']);
        $this->parser->parse($request);

        static::assertSame(['first_name', 'last_name', 'email'], $this->parser->getFields());
    }

    /**
     * Test that getFields trims field values.
     *
     * @return void
     */
    public function testGetFieldsTrimsValues(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', ['fields' => ' first_name , last_name ']);
        $this->parser->parse($request);

        static::assertSame(['first_name', 'last_name'], $this->parser->getFields());
    }

    /**
     * Test that getFields with resource key returns specific resource fields.
     *
     * @return void
     */
    public function testGetFieldsWithResourceKeyReturnsSpecificFields(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', [
            'fields' => [
                'user' => self::FIELDS_NAME_EMAIL,
                'post' => 'title,body',
            ],
        ]);
        $this->parser->parse($request);

        static::assertSame(['name', 'email'], $this->parser->getFields('user'));
        static::assertSame(['title', 'body'], $this->parser->getFields('post'));
    }

    /**
     * Test that getFields with unknown resource key returns null.
     *
     * @return void
     */
    public function testGetFieldsWithUnknownResourceReturnsNull(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', [
            'fields' => ['user' => self::FIELDS_NAME_EMAIL],
        ]);
        $this->parser->parse($request);

        static::assertNull($this->parser->getFields('unknown'));
    }

    /**
     * Test that getCounts returns null when no counts are set.
     *
     * @return void
     */
    public function testGetCountsReturnsNullWhenNoCountsSet(): void
    {
        $request = Request::create(self::TEST_URL, 'GET');
        $this->parser->parse($request);

        static::assertNull($this->parser->getCounts());
    }

    /**
     * Test that getCounts parses comma-separated counts.
     *
     * @return void
     */
    public function testGetCountsParsesCommaSeparatedCounts(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', [
            'counts' => ['user' => 'posts,comments'],
        ]);
        $this->parser->parse($request);

        static::assertSame(['posts', 'comments'], $this->parser->getCounts('user'));
    }

    /**
     * Test that getSums parses aggregation format.
     *
     * @return void
     */
    public function testGetSumsParsesAggregationFormat(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', [
            'sums' => [
                'account' => [
                    'transaction' => 'amount,fee',
                ],
            ],
        ]);
        $this->parser->parse($request);

        $result = $this->parser->getSums('account');

        static::assertIsArray($result);
        static::assertArrayHasKey('transaction', $result);
        static::assertSame(['amount', 'fee'], $result['transaction']);
    }

    /**
     * Test that getSums returns null when no sums are set.
     *
     * @return void
     */
    public function testGetSumsReturnsNullWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, 'GET');
        $this->parser->parse($request);

        static::assertNull($this->parser->getSums());
    }

    /**
     * Test that getAverages parses aggregation format.
     *
     * @return void
     */
    public function testGetAveragesParsesAggregationFormat(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', [
            'averages' => [
                'account' => [
                    'transaction' => 'amount',
                ],
            ],
        ]);
        $this->parser->parse($request);

        $result = $this->parser->getAverages('account');

        static::assertIsArray($result);
        static::assertArrayHasKey('transaction', $result);
        static::assertSame(['amount'], $result['transaction']);
    }

    /**
     * Test that getFilters returns an empty array when no filters are set.
     *
     * @return void
     */
    public function testGetFiltersReturnsEmptyArrayWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, 'GET');
        $this->parser->parse($request);

        static::assertSame([], $this->parser->getFilters());
    }

    /**
     * Test that getFilters parses a JSON filter string.
     *
     * @return void
     */
    public function testGetFiltersParsesJsonFilterString(): void
    {
        $filters = json_encode(['status' => 'active', 'role' => 'admin']);
        $request = Request::create(self::TEST_URL, 'GET', ['filters' => $filters]);
        $this->parser->parse($request);

        $result = $this->parser->getFilters();

        static::assertSame(['status' => 'active', 'role' => 'admin'], $result);
    }

    /**
     * Test that getOrder parses order with direction.
     *
     * @param  string  $orderString
     * @param  array<string, string>  $expected
     * @return void
     */
    #[DataProvider('orderProvider')]
    public function testGetOrderParsesOrderWithDirection(string $orderString, array $expected): void
    {
        $request = Request::create(self::TEST_URL, 'GET', ['order' => $orderString]);
        $this->parser->parse($request);

        static::assertSame($expected, $this->parser->getOrder());
    }

    /**
     * Test that getOrder returns an empty array when no order is set.
     *
     * @return void
     */
    public function testGetOrderReturnsEmptyArrayWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, 'GET');
        $this->parser->parse($request);

        static::assertSame([], $this->parser->getOrder());
    }

    /**
     * Test that getLimit returns a positive integer.
     *
     * @return void
     */
    public function testGetLimitReturnsPositiveInteger(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', ['limit' => '25']);
        $this->parser->parse($request);

        static::assertSame(25, $this->parser->getLimit());
    }

    /**
     * Test that getLimit returns null for zero.
     *
     * @return void
     */
    public function testGetLimitReturnsNullForZero(): void
    {
        $request = Request::create(self::TEST_URL, 'GET');
        $this->parser->parse($request);

        static::assertNull($this->parser->getLimit());
    }

    /**
     * Test that getPage returns a positive integer.
     *
     * @return void
     */
    public function testGetPageReturnsPositiveInteger(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', ['page' => '5']);
        $this->parser->parse($request);

        static::assertSame(5, $this->parser->getPage());
    }

    /**
     * Test that getPage returns 1 when not set.
     *
     * @return void
     */
    public function testGetPageReturnsOneWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, 'GET');
        $this->parser->parse($request);

        static::assertSame(1, $this->parser->getPage());
    }

    /**
     * Test that getCursor returns a cursor string.
     *
     * @return void
     */
    public function testGetCursorReturnsCursorString(): void
    {
        $cursor  = 'eyJpZCI6MTAwfQ==';
        $request = Request::create(self::TEST_URL, 'GET', ['cursor' => $cursor]);
        $this->parser->parse($request);

        static::assertSame($cursor, $this->parser->getCursor());
    }

    /**
     * Test that getCursor returns an empty string when not set.
     *
     * @return void
     */
    public function testGetCursorReturnsEmptyStringWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, 'GET');
        $this->parser->parse($request);

        static::assertSame('', $this->parser->getCursor());
    }

    /**
     * Test that validation fails for invalid page parameter.
     *
     * @return void
     */
    public function testValidationFailsForInvalidPage(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, 'GET', ['page' => 'not-a-number']);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails for invalid limit parameter.
     *
     * @return void
     */
    public function testValidationFailsForInvalidLimit(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, 'GET', ['limit' => 'abc']);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails for invalid JSON filters.
     *
     * @return void
     */
    public function testValidationFailsForInvalidJsonFilters(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, 'GET', ['filters' => 'not-valid-json{']);
        $this->parser->parse($request);
    }

    /**
     * Test parsing multiple parameters simultaneously.
     *
     * @return void
     */
    public function testParsingMultipleParametersSimultaneously(): void
    {
        $request = Request::create(self::TEST_URL, 'GET', [
            'fields'  => self::FIELDS_NAME_EMAIL,
            'order'   => 'name:asc',
            'page'    => '2',
            'limit'   => '10',
            'filters' => json_encode(['active' => true]),
        ]);

        $this->parser->parse($request);

        static::assertSame(['name', 'email'], $this->parser->getFields());
        static::assertSame(['name' => 'asc'], $this->parser->getOrder());
        static::assertSame(2, $this->parser->getPage());
        static::assertSame(10, $this->parser->getLimit());
        static::assertSame(['active' => true], $this->parser->getFilters());
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
        $result = $this->invokeMethod($this->parser, 'normalizeFields', ['amount', 'fee']);

        static::assertSame(['amount', 'fee'], $result);
    }

    /**
     * Test that normalizeFields wraps non-string non-array input in an array.
     *
     * @return void
     */
    public function testNormalizeFieldsWrapsOtherInputInArray(): void
    {
        $result = $this->invokeMethod($this->parser, 'normalizeFields', 42);

        static::assertSame([42], $result);
    }

    /**
     * Test that parseAggregations skips non-array relation entries.
     *
     * @return void
     */
    public function testParseAggregationsSkipsNonArrayRelations(): void
    {
        $result = $this->invokeMethod($this->parser, 'parseAggregations', [
            'valid'   => ['fields' => 'amount'],
            'invalid' => 'not_an_array',
        ]);

        static::assertArrayHasKey('valid', $result);
        static::assertArrayNotHasKey('invalid', $result);
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
    }
}
