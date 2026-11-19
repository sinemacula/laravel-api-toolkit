<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\ApiQueryParser;
use SineMacula\ApiToolkit\Enums\TrashedState;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Query\ApiQuerySnapshot;
use SineMacula\Http\Enums\HttpMethod;
use Tests\Concerns\InteractsWithNonPublicMembers;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\UserResource;
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

        Config::set(ResourceDiscovery::RESOURCES_KEY, [UserResource::class, PostResource::class]);

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

        self::assertNull($this->parser->getFields('users'));
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
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ['users' => ' name , email ']]);
        $this->parser->parse($request);

        self::assertSame(['name', 'email'], $this->parser->getFields('users'));
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
                'users' => self::FIELDS_NAME_EMAIL,
                'posts' => 'title,body',
            ],
        ]);
        $this->parser->parse($request);

        self::assertSame(['name', 'email'], $this->parser->getFields('users'));
        self::assertSame(['title', 'body'], $this->parser->getFields('posts'));
    }

    /**
     * Test that getFields with unknown resource key returns null.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFieldsWithUnrequestedResourceReturnsNull(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'fields' => ['users' => self::FIELDS_NAME_EMAIL],
        ]);
        $this->parser->parse($request);

        self::assertNull($this->parser->getFields('posts'));
    }

    /**
     * Test that getAggregates returns null when no aggregates are set.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetAggregatesReturnsNullWhenNoAggregatesSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertNull($this->parser->getAggregates('users'));
    }

    /**
     * Test that getAggregates parses comma-separated aggregate keys.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetAggregatesParsesCommaSeparatedKeys(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'aggregates' => ['users' => 'posts_count, posts_sum_id'],
        ]);
        $this->parser->parse($request);

        self::assertSame(['posts_count', 'posts_sum_id'], $this->parser->getAggregates('users'));
    }

    /**
     * Test that the retired counts, sums and averages parameters are no longer
     * read.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRetiredAggregateParametersAreNotRead(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'counts'   => ['user' => 'posts'],
            'sums'     => ['user' => ['orders' => 'total']],
            'averages' => ['user' => ['orders' => 'total']],
        ]);
        $this->parser->parse($request);

        self::assertNull($this->parser->getAggregates('user'));
        self::assertSame([], $this->parsedParameters());
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
     * Test that getFilters keeps every falsy filter value and reads an empty
     * document as no filters.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetFiltersKeepsFalsyValues(): void
    {
        $filters = ['name' => '0', 'email' => '', 'organization_id' => 0, 'active' => false, 'status' => ['$eq' => '0']];

        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => json_encode($filters)]));

        self::assertSame($filters, $this->parser->getFilters());

        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['filters' => '{}']));

        self::assertSame([], $this->parser->getFilters());
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
     * Test that parsing rejects a valid-JSON but non-associative filter value.
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
     * Test that fields listed without a resource key are rejected as an unknown
     * type.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testListFormFieldsAreRejectedAsAnUnknownType(): void
    {
        $this->assertParseRejected(['fields' => ['name']], ['fields.0' => ['The "0" resource type is not known.']]);
    }

    /**
     * Test that aggregates listed without a resource key are rejected as an
     * unknown type.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testListFormAggregatesAreRejectedAsAnUnknownType(): void
    {
        $this->assertParseRejected(['aggregates' => ['posts_count']], ['aggregates.0' => ['The "0" resource type is not known.']]);
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
     * Test that a limit at the configured ceiling is returned as asked for.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetLimitReturnsALimitAtTheConfiguredCeiling(): void
    {
        config()->set('api-toolkit.parser.max_limit', 100);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => '100']);
        $this->parser->parse($request);

        self::assertSame(100, $this->parser->getLimit());
    }

    /**
     * Test that a limit one above the configured ceiling is rejected rather
     * than reduced to the ceiling.
     *
     * @return void
     */
    public function testParseRejectsALimitAboveTheConfiguredCeiling(): void
    {
        config()->set('api-toolkit.parser.max_limit', 100);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => '101']);

        try {
            $this->parser->parse($request);

            self::fail('Expected a limit above the ceiling to be rejected.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => 'limit',
                'pointer'   => '',
                'reason'    => 'max_limit',
                'limit'     => 100,
                'actual'    => 101,
            ], $exception->getCustomMeta());
        }
    }

    /**
     * Test that getLimit returns the requested value untouched when the ceiling
     * is disabled.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetLimitReturnsTheRequestedValueWhenTheCeilingIsDisabled(): void
    {
        config()->set('api-toolkit.parser.max_limit', 0);

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
     * Test that getCursor coerces a non-string scalar into its string form.
     *
     * @return void
     */
    public function testGetCursorCastsNonStringScalarToString(): void
    {
        $this->seedParameters(['cursor' => 100]);

        self::assertSame('100', $this->parser->getCursor());
    }

    /**
     * Test that getSearch returns the parsed term.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetSearchReturnsTheParsedTerm(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['search' => 'john smith']);
        $this->parser->parse($request);

        self::assertSame('john smith', $this->parser->getSearch()?->value());
    }

    /**
     * Test that getSearch returns null when no term was supplied.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testGetSearchReturnsNullWhenNotSet(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb());
        $this->parser->parse($request);

        self::assertNull($this->parser->getSearch());
    }

    /**
     * Test that getSearch returns null for a stored parameter that is not a
     * parsed term.
     *
     * @return void
     */
    public function testGetSearchReturnsNullForAnUnparsedParameter(): void
    {
        $this->seedParameters(['search' => 'smith']);

        self::assertNull($this->parser->getSearch());
    }

    /**
     * Test that reset clears a parsed search term.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testResetClearsTheParsedSearchTerm(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['search' => 'smith']);
        $this->parser->parse($request);

        $this->parser->reset();

        self::assertNull($this->parser->getSearch());
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
            'fields'  => ['users' => self::FIELDS_NAME_EMAIL],
            'order'   => 'name:asc',
            'page'    => '2',
            'limit'   => '10',
            'filters' => json_encode(['active' => true]),
        ]);

        $this->parser->parse($request);

        self::assertSame(['name', 'email'], $this->parser->getFields('users'));
        self::assertSame(['name' => 'asc'], $this->parser->getOrder());
        self::assertSame(2, $this->parser->getPage());
        self::assertSame(10, $this->parser->getLimit());
        self::assertSame(['active' => true], $this->parser->getFilters());
    }

    /**
     * Test that getFields trims the values stored for a type, and that an empty
     * type selects nothing.
     *
     * @return void
     */
    public function testGetFieldsTrimsStoredParameterValues(): void
    {
        $this->seedParameters(['fields' => ['users' => [' first_name ', ' last_name ']]]);

        self::assertSame(['first_name', 'last_name'], $this->parser->getFields('users'));
        self::assertNull($this->parser->getFields(''));
    }

    /**
     * Test that getAggregates trims the string values stored in the parameter
     * state, skipping any other value.
     *
     * @return void
     */
    public function testGetAggregatesTrimsStoredParameterValues(): void
    {
        $this->seedParameters(['aggregates' => ['users' => [' posts_count ', 7, ' orders_sum_total ']]]);

        self::assertSame(['posts_count', 'orders_sum_total'], $this->parser->getAggregates('users'));
    }

    /**
     * Test that getLimit truncates fractional values below one to null.
     *
     * @return void
     */
    public function testGetLimitReturnsNullForFractionalValueBelowOne(): void
    {
        $this->seedParameters(['limit' => '0.5']);

        self::assertNull($this->parser->getLimit());
    }

    /**
     * Test that getPage truncates fractional values below one to the first
     * page.
     *
     * @return void
     */
    public function testGetPageReturnsOneForFractionalValueBelowOne(): void
    {
        $this->seedParameters(['page' => '0.5']);

        self::assertSame(1, $this->parser->getPage());
    }

    /**
     * Test that parse trims whitespace from the page and limit parameters.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testParseTrimsPageAndLimitParameters(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['page' => ' 2 ', 'limit' => ' 10 ']);
        $this->parser->parse($request);

        $parameters = $this->parsedParameters();

        self::assertSame('2', $parameters['page']);
        self::assertSame('10', $parameters['limit']);
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
     * Test that everything after the first colon is read as one direction, and
     * rejected when it is not asc or desc.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testParseRejectsEverythingAfterTheFirstColonAsOneDirection(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['order' => 'name:desc:extra']);

        try {
            $this->parser->parse($request);
            self::fail('Expected a ValidationException for the order direction.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['order.name' => ['The "desc:extra" direction is not permitted on the "name" key, which accepts asc, desc.']],
                $exception->errors(),
            );
        }
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
            'fields' => ['users' => ['name', 'email']],
        ]);
        $this->parser->parse($request);
    }

    /**
     * Test that validation fails when an aggregates resource value is an array.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testValidationFailsWhenAggregatesResourceValueIsArray(): void
    {
        $this->expectException(ValidationException::class);

        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), [
            'aggregates' => ['users' => ['posts_count', 'posts_sum_id']],
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
        $request = Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ['users' => self::FIELDS_NAME_EMAIL]]);
        $this->parser->parse($request);

        self::assertSame(['name', 'email'], $this->parser->getFields('users'));

        $this->parser->reset();

        self::assertNull($this->parser->getFields('users'));
    }

    /**
     * Provide each request shape and whether it reads by cursor.
     *
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function cursorPaginationProvider(): iterable
    {
        yield 'pagination names cursor' => [['pagination' => 'cursor'], true];
        yield 'a cursor token is carried' => [['cursor' => 'eyJpZCI6MX0'], true];
        yield 'an empty cursor is still a cursor read' => [['cursor' => ''], true];
        yield 'an empty pagination mode is not one' => [['pagination' => ''], false];
        yield 'neither is present' => [[], false];
        yield 'a page number alone is not one' => [['page' => '500'], false];
    }

    /**
     * Test that a request reads by cursor from either the pagination mode or a
     * carried cursor token.
     *
     * @param  array<string, string>  $parameters
     * @param  bool  $expected
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('cursorPaginationProvider')]
    public function testCursorPaginationIsRecognised(array $parameters, bool $expected): void
    {
        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), $parameters));

        self::assertSame($expected, $this->parser->isCursorPaginated());
    }

    /**
     * Test that the page size resolves to the configured default when the
     * client leaves it out.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testResolvedLimitFallsBackToTheConfiguredDefault(): void
    {
        Config::set('api-toolkit.parser.defaults.limit', '25');

        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb()));

        self::assertSame(25, $this->parser->getResolvedLimit());

        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['limit' => '10']));

        self::assertSame(10, $this->parser->getResolvedLimit());
    }

    /**
     * Provide the unusable configured defaults a page size may carry, with how
     * the message names each.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function unusablePageSizeProvider(): iterable
    {
        yield 'not numeric' => ['not-a-number', '\'not-a-number\''];
        yield 'empty environment value' => ['', '\'\''];
        yield 'zero' => [0, '0'];
        yield 'negative' => [-10, '-10'];
        yield 'absent' => [null, 'null'];
    }

    /**
     * Test that an unusable configured page size fails rather than serving a
     * page of some other size.
     *
     * @param  mixed  $configured
     * @param  string  $given
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('unusablePageSizeProvider')]
    public function testUnusableConfiguredPageSizeFails(mixed $configured, string $given): void
    {
        Config::set('api-toolkit.parser.defaults.limit', $configured);

        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb()));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('The api-toolkit.parser.defaults.limit setting must be an integer of at least 1; [%s] given.', $given));

        $this->parser->getResolvedLimit();
    }

    /**
     * Test that parameters carried in the request body are never read.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testBodyParametersAreIgnored(): void
    {
        $request = Request::create(self::TEST_URL, HttpMethod::POST->getVerb(), [
            'fields'  => ['users' => [['nested' => true]]],
            'filters' => ['status' => 'active'],
            'page'    => 2,
            'trashed' => 'deleted',
            'cursor'  => 'eyJpZCI6MX0',
        ]);

        $this->parser->parse($request);

        self::assertNull($this->parser->getFields('users'));
        self::assertSame([], $this->parser->getFilters());
        self::assertSame(1, $this->parser->getPage());
        self::assertSame('', $this->parser->getCursor());
        self::assertFalse($this->parser->isCursorPaginated());
    }

    /**
     * Test that the query string is read when the body carries the same
     * parameter.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testQueryStringIsReadBesideAConflictingBody(): void
    {
        $request = Request::create(self::TEST_URL . '?limit=1', HttpMethod::POST->getVerb(), ['limit' => '2']);

        $this->parser->parse($request);

        self::assertSame(1, $this->parser->getLimit());
    }

    /**
     * Test that a rejected parse keeps the parameters of the previous one.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testRejectedParseAssignsNothing(): void
    {
        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ['users' => 'name'], 'page' => '2']));

        $token = $this->parser->token();

        $this->assertParseRejected(['fields' => ['users' => 'emial'], 'page' => '3', 'cursor' => 'x'], [
            'fields.users' => ['The "emial" field is not declared on the "users" resource type.'],
        ]);

        self::assertSame(['name'], $this->parser->getFields('users'));
        self::assertSame(2, $this->parser->getPage());
        self::assertFalse($this->parser->isCursorPaginated());
        self::assertSame($token, $this->parser->token());
        self::assertSame(['fields' => ['users' => 'name']], $this->parser->snapshot()->parameters);
    }

    /**
     * Test that the parser reads as parsed only between a parse and a reset.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testIsParsedFollowsParseAndReset(): void
    {
        self::assertFalse($this->parser->isParsed());

        $this->parser->parse(Request::create(self::TEST_URL));

        self::assertTrue($this->parser->isParsed());

        $this->parser->reset();

        self::assertFalse($this->parser->isParsed());
    }

    /**
     * Test that reset clears cursor pagination along with the parameters.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testResetClearsCursorPagination(): void
    {
        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['pagination' => 'cursor']));

        $this->parser->reset();

        self::assertFalse($this->parser->isCursorPaginated());
    }

    /**
     * Test that every parse gets a token of its own, that using() restores the
     * outer one and that reset leaves none.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testTokenIdentifiesTheCurrentQuery(): void
    {
        self::assertNull($this->parser->token());

        $this->parser->parse(Request::create(self::TEST_URL));

        $first = $this->parser->token();

        $this->parser->parse(Request::create(self::TEST_URL));

        $second = $this->parser->token();
        $inner  = $this->parser->using(new ApiQuerySnapshot([]), fn (): ?object => $this->parser->token());

        self::assertNotNull($first);
        self::assertNotSame($first, $second);
        self::assertNotNull($inner);
        self::assertNotSame($second, $inner);
        self::assertSame($second, $this->parser->token());

        $this->parser->reset();

        self::assertNull($this->parser->token());
    }

    /**
     * Test that a snapshot of an unparsed parser is refused.
     *
     * @return void
     */
    public function testSnapshotRefusesAnUnparsedQuery(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No API query has been parsed to snapshot.');

        $this->parser->snapshot();
    }

    /**
     * Test that a snapshot carries the selection exactly as sent, and never the
     * page.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testSnapshotCarriesTheSelectionAsSentWithoutThePage(): void
    {
        $selection = [
            'fields'     => ['users' => ' name , email '],
            'aggregates' => ['users' => 'posts_count'],
            'filters'    => '{"status":{"$eq":"paid"}}',
            'search'     => 'john',
            'order'      => 'name:desc',
            'trashed'    => 'with',
        ];

        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), $selection + [
            'page'       => '2',
            'limit'      => '5',
            'cursor'     => 'eyJpZCI6MX0',
            'pagination' => 'cursor',
            'unrelated'  => 'x',
        ]));

        self::assertSame($selection, $this->parser->snapshot()->parameters);
    }

    /**
     * Test that every getter reads the snapshot inside using(), on its first
     * page and unlimited, and the parsed query again once it returns.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUsingReadsTheSnapshotInPlaceOfTheParsedQuery(): void
    {
        $outer = [
            'fields'     => ['users' => 'name'],
            'filters'    => '{"status":"active"}',
            'page'       => '3',
            'limit'      => '5',
            'cursor'     => 'eyJpZCI6MX0',
            'pagination' => 'cursor',
        ];

        $snapshot = new ApiQuerySnapshot([
            'fields'     => ['users' => 'email'],
            'aggregates' => ['users' => 'posts_count'],
            'filters'    => '{"status":"paid"}',
            'search'     => 'john',
            'order'      => 'name:desc',
            'trashed'    => 'only',
        ]);

        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), $outer));

        $inside = $this->parser->using($snapshot, fn (): array => $this->readState());

        self::assertSame([
            'fields'     => ['email'],
            'aggregates' => ['posts_count'],
            'filters'    => ['status' => 'paid'],
            'search'     => 'john',
            'order'      => ['name' => 'desc'],
            'trashed'    => TrashedState::ONLY,
            'page'       => 1,
            'limit'      => null,
            'cursor'     => '',
            'cursorRead' => false,
            'snapshot'   => $snapshot->parameters,
        ], $inside);

        self::assertSame([
            'fields'     => ['name'],
            'aggregates' => null,
            'filters'    => ['status' => 'active'],
            'search'     => null,
            'order'      => [],
            'trashed'    => TrashedState::NONE,
            'page'       => 3,
            'limit'      => 5,
            'cursor'     => 'eyJpZCI6MX0',
            'cursorRead' => true,
            'snapshot'   => ['fields' => ['users' => 'name'], 'filters' => '{"status":"active"}'],
        ], $this->readState());
    }

    /**
     * Test that using() restores the parsed query when the callback throws.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUsingRestoresTheQueryWhenTheCallbackThrows(): void
    {
        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ['users' => 'name']]));

        $token = $this->parser->token();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The callback failed.');

        try {
            $this->parser->using(new ApiQuerySnapshot(['fields' => ['users' => 'email']]), static fn (): bool => throw new \RuntimeException('The callback failed.'));
        } finally {
            self::assertSame(['name'], $this->parser->getFields('users'));
            self::assertSame($token, $this->parser->token());
        }
    }

    /**
     * Test that nested using() calls each restore the query they replaced.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNestedUsingRestoresEachQueryInTurn(): void
    {
        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ['users' => 'name']]));

        $outer = new ApiQuerySnapshot(['fields' => ['users' => 'email']]);
        $inner = new ApiQuerySnapshot(['fields' => ['users' => 'status']]);

        $seen = $this->parser->using($outer, fn (): array => [
            $this->parser->getFields('users'),
            $this->parser->using($inner, fn (): ?array => $this->parser->getFields('users')),
            $this->parser->getFields('users'),
        ]);

        self::assertSame([['email'], ['status'], ['email']], $seen);
        self::assertSame(['name'], $this->parser->getFields('users'));
    }

    /**
     * Provide snapshots the parse-level checks refuse, with the refusal each
     * raises.
     *
     * @return iterable<string, array{array<string, mixed>, class-string<\Throwable>}>
     */
    public static function refusedSnapshotProvider(): iterable
    {
        yield 'filters that are not an object' => [['filters' => '[1]'], ValidationException::class];
        yield 'filters over the byte cap' => [['filters' => '{"name":"' . str_repeat('a', 64) . '"}'], QueryTooExpensiveException::class];
    }

    /**
     * Test that a snapshot the parse-level checks refuse fails before the
     * callback runs and leaves the parsed query in place.
     *
     * @param  array<string, mixed>  $parameters
     * @param  class-string<\Throwable>  $refusal
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('refusedSnapshotProvider')]
    public function testUsingRefusesASnapshotBeforeTheCallback(array $parameters, string $refusal): void
    {
        Config::set('api-toolkit.query_cost.max_bytes', 32);

        $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), ['fields' => ['users' => 'name']]));

        $token = $this->parser->token();
        $ran   = false;

        try {
            $this->parser->using(new ApiQuerySnapshot($parameters), static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('Expected the snapshot to be refused.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf($refusal, $exception);
        }

        self::assertFalse($ran);
        self::assertSame(['name'], $this->parser->getFields('users'));
        self::assertSame($token, $this->parser->token());
    }

    /**
     * Test that using() on an unparsed parser leaves it unparsed afterwards.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testUsingOnAnUnparsedParserLeavesItUnparsed(): void
    {
        self::assertTrue($this->parser->using(new ApiQuerySnapshot([]), fn (): bool => $this->parser->isParsed()));
        self::assertFalse($this->parser->isParsed());
        self::assertNull($this->parser->token());
    }

    /**
     * Test that every change of query drops the eager-load plans, which read
     * child selections from the query.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testEveryChangeOfQueryDropsTheEagerLoadPlans(): void
    {
        $this->seedEagerLoadPlan();
        $this->parser->parse(Request::create(self::TEST_URL));

        self::assertSame([], $this->getStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache'));

        $this->seedEagerLoadPlan();

        $inside = $this->parser->using(new ApiQuerySnapshot([]), function (): mixed {
            $plans = $this->getStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache');

            $this->seedEagerLoadPlan();

            return $plans;
        });

        self::assertSame([], $inside);
        self::assertSame([], $this->getStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache'));

        $this->seedEagerLoadPlan();
        $this->parser->reset();

        self::assertSame([], $this->getStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache'));
    }

    /**
     * Read every value the parser exposes.
     *
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        return [
            'fields'     => $this->parser->getFields('users'),
            'aggregates' => $this->parser->getAggregates('users'),
            'filters'    => $this->parser->getFilters(),
            'search'     => $this->parser->getSearch()?->value(),
            'order'      => $this->parser->getOrder(),
            'trashed'    => $this->parser->getTrashed(),
            'page'       => $this->parser->getPage(),
            'limit'      => $this->parser->getLimit(),
            'cursor'     => $this->parser->getCursor(),
            'cursorRead' => $this->parser->isCursorPaginated(),
            'snapshot'   => $this->parser->snapshot()->parameters,
        ];
    }

    /**
     * Seed the parser state with the given extracted parameters.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     */
    private function seedParameters(array $parameters): void
    {
        $this->setProperty($this->parser, 'state', [
            'parameters'      => $parameters,
            'cursorPaginated' => false,
            'snapshot'        => new ApiQuerySnapshot([]),
            'token'           => new \stdClass,
        ]);
    }

    /**
     * Return the extracted parameters of the parsed query.
     *
     * @return array<string, mixed>
     */
    private function parsedParameters(): array
    {
        /** @var array{parameters: array<string, mixed>} $state */
        $state = $this->getProperty($this->parser, 'state');

        return $state['parameters'];
    }

    /**
     * Seed the eager-load memo with a plan.
     *
     * @return void
     */
    private function seedEagerLoadPlan(): void
    {
        $this->setStaticProperty(EagerLoadPlanner::class, 'eagerLoadCache', ['seeded' => ['posts']]);
    }

    /**
     * Assert that parsing the given query parameters is rejected with exactly
     * the given errors.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, array<int, string>>  $errors
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function assertParseRejected(array $parameters, array $errors): void
    {
        try {
            $this->parser->parse(Request::create(self::TEST_URL, HttpMethod::GET->getVerb(), $parameters));
            self::fail('Expected the parse to be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame($errors, $exception->errors());
        }
    }
}
