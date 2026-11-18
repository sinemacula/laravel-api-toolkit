<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\ApiToolkit\Search\SearchTerm;
use Tests\Concerns\LocalisesToolkitMessages;
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
    use LocalisesToolkitMessages;

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
     * Test that the parser reads exactly the toolkit's query parameters.
     *
     * @return void
     */
    public function testParametersListEveryParameterTheParserReads(): void
    {
        self::assertSame(
            ['page', 'limit', 'cursor', 'fields', 'aggregates', 'filters', 'search', 'order', 'trashed'],
            QueryParameterExtractor::PARAMETERS,
        );
    }

    /**
     * Test that a parameter the parser does not read is never extracted.
     *
     * @return void
     */
    public function testExtractIgnoresParametersTheParserDoesNotRead(): void
    {
        $input = ['q' => 'x', 'pretty' => '1', 'pagination' => 'cursor', 'limit' => '5'];

        self::assertSame(['limit' => '5'], $this->extractor->extract($input));
    }

    /**
     * Provide trashed values of any shape.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function trashedValueProvider(): iterable
    {
        yield 'padded' => [' WITH '];
        yield 'list' => [['with']];
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'boolean' => [true];
    }

    /**
     * Test that extract keeps the trashed parameter exactly as sent, so the
     * validator refuses anything but an exact state.
     *
     * @param  mixed  $trashed
     * @return void
     */
    #[DataProvider('trashedValueProvider')]
    public function testExtractKeepsTheTrashedParameterAsSent(mixed $trashed): void
    {
        $input = ['trashed' => $trashed];

        self::assertSame(['trashed' => $trashed], $this->extractor->extract($input));
    }

    /**
     * Test that extract returns an empty array when no parameters are supplied.
     *
     * @return void
     */
    public function testExtractReturnsEmptyArrayWhenNoParametersSupplied(): void
    {
        $input = [];

        self::assertSame([], $this->extractor->extract($input));
    }

    /**
     * Test that extract only includes the keys present on the request.
     *
     * @return void
     */
    public function testExtractOnlyIncludesPresentKeys(): void
    {
        $input = ['fields' => ['users' => 'name']];

        self::assertSame(['fields' => ['users' => ['name']]], $this->extractor->extract($input));
    }

    /**
     * Test that extract trims the page and limit parameters.
     *
     * @return void
     */
    public function testExtractTrimsPageAndLimitParameters(): void
    {
        $input = ['page' => ' 2 ', 'limit' => ' 10 '];

        $parameters = $this->extractor->extract($input);

        self::assertSame('2', $parameters['page']);
        self::assertSame('10', $parameters['limit']);
    }

    /**
     * Test that extract keeps an integer page and limit, as a request built in
     * code carries them, instead of failing on them.
     *
     * @return void
     */
    public function testExtractKeepsIntegerPageAndLimitParameters(): void
    {
        $input = ['page' => 2, 'limit' => 10];

        $parameters = $this->extractor->extract($input);

        self::assertSame(2, $parameters['page']);
        self::assertSame(10, $parameters['limit']);
    }

    /**
     * Test that extract passes the cursor through unchanged.
     *
     * @return void
     */
    public function testExtractPassesCursorThroughUnchanged(): void
    {
        $cursor = 'eyJpZCI6MTAwfQ==';
        $input  = ['cursor' => $cursor];

        $parameters = $this->extractor->extract($input);

        self::assertSame($cursor, $parameters['cursor']);
    }

    /**
     * Test that extract splits and trims a comma-separated fields string.
     *
     * @return void
     */
    public function testExtractSplitsAndTrimsFieldsString(): void
    {
        $input = ['fields' => ['users' => ' first_name , last_name ']];

        $parameters = $this->extractor->extract($input);

        self::assertSame(['users' => ['first_name', 'last_name']], $parameters['fields']);
    }

    /**
     * Test that a null selection, as the empty-string middleware leaves an
     * empty one, reads as a single empty name.
     *
     * @return void
     */
    public function testExtractReadsANullSelectionAsEmpty(): void
    {
        $input = [
            'fields'     => ['users' => null],
            'aggregates' => ['users' => null],
        ];

        self::assertSame([
            'fields'     => ['users' => ['']],
            'aggregates' => ['users' => ['']],
        ], $this->extractor->extract($input));
    }

    /**
     * Test that extract parses a fields array per resource.
     *
     * @return void
     */
    public function testExtractParsesFieldsArrayPerResource(): void
    {
        $input = [
            'fields' => [
                'user' => 'name,email',
                'post' => 'title,body',
            ],
        ];

        $parameters = $this->extractor->extract($input);

        self::assertSame([
            'user' => ['name', 'email'],
            'post' => ['title', 'body'],
        ], $parameters['fields']);
    }

    /**
     * Test that extract splits and trims a comma-separated aggregates string.
     *
     * @return void
     */
    public function testExtractSplitsAndTrimsAggregatesString(): void
    {
        $input = ['aggregates' => ['users' => ' posts_count , orders_sum_total ']];

        $parameters = $this->extractor->extract($input);

        self::assertSame(['users' => ['posts_count', 'orders_sum_total']], $parameters['aggregates']);
    }

    /**
     * Test that extract parses an aggregates array per resource, and reads none
     * of the retired counts, sums and averages parameters.
     *
     * @return void
     */
    public function testExtractParsesAggregatesArrayPerResource(): void
    {
        $input = [
            'aggregates' => ['user' => 'posts_count,orders_sum_total'],
            'counts'     => ['user' => 'posts'],
            'sums'       => ['user' => ['orders' => 'total']],
            'averages'   => ['user' => ['orders' => 'total']],
        ];

        $parameters = $this->extractor->extract($input);

        self::assertSame(['aggregates' => ['user' => ['posts_count', 'orders_sum_total']]], $parameters);
    }

    /**
     * Test that extract decodes a JSON filter string.
     *
     * @return void
     */
    public function testExtractDecodesJsonFilters(): void
    {
        $filters = json_encode(['status' => 'active', 'role' => 'admin']);
        $input   = ['filters' => $filters];

        $parameters = $this->extractor->extract($input);

        self::assertSame(['status' => 'active', 'role' => 'admin'], $parameters['filters']);
    }

    /**
     * Test that extract rejects an undecodable filters document.
     *
     * @return void
     */
    public function testExtractRejectsUndecodableFilters(): void
    {
        $input = ['filters' => 'not-valid-json{'];

        $this->assertRejectsFilters($input);
    }

    /**
     * Test that extract rejects a filters document nested beyond the decoder
     * depth limit.
     *
     * @return void
     */
    public function testExtractRejectsFiltersNestedBeyondTheDecoderDepthLimit(): void
    {
        $filters = str_repeat('{"a":', 512) . '1' . str_repeat('}', 512);
        $input   = ['filters' => $filters];

        $this->assertRejectsFilters($input);
    }

    /**
     * Test that extract accepts a filters document nested to exactly the
     * decoder depth limit.
     *
     * @return void
     */
    public function testExtractAcceptsFiltersNestedToTheDecoderDepthLimit(): void
    {
        $filters = str_repeat('{"a":', 511) . '1' . str_repeat('}', 511);
        $input   = ['filters' => $filters];

        $parameters = $this->extractor->extract($input);

        self::assertIsArray($parameters['filters']);
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
     * Test that extract rejects a valid-JSON but non-associative filter value.
     *
     * @param  string  $filters
     * @return void
     */
    #[DataProvider('nonAssociativeFilterProvider')]
    public function testExtractRejectsNonAssociativeFilters(string $filters): void
    {
        $input = ['filters' => $filters];

        $this->assertRejectsFilters($input);
    }

    /**
     * Test that the filters rejection is worded in the current locale.
     *
     * @return void
     */
    public function testFiltersRejectionIsWordedInTheCurrentLocale(): void
    {
        $this->localiseToolkitMessages(['filters.not_object' => 'Der Parameter filters muss ein JSON-Objekt sein.']);

        $input = ['filters' => '[1]'];

        try {
            $this->extractor->extract($input);
            self::fail('Expected a ValidationException for the filters parameter.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters' => ['Der Parameter filters muss ein JSON-Objekt sein.']], $exception->errors());
        }
    }

    /**
     * Test that an empty JSON object is accepted as a filter-free document.
     *
     * @return void
     */
    public function testExtractAcceptsAnEmptyFilterDocument(): void
    {
        $input = ['filters' => '{}'];

        $parameters = $this->extractor->extract($input);

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
        yield 'upper-case direction' => ['name:DESC', ['name' => 'desc']];
        yield 'padded mixed-case direction' => [' name : Desc ', ['name' => 'desc']];
        yield 'padded ascending direction' => ['name: ASC ', ['name' => 'asc']];
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
        $input = ['order' => $orderString];

        $parameters = $this->extractor->extract($input);

        self::assertSame($expected, $parameters['order']);
    }

    /**
     * Test that an empty order segment never produces an empty-string column
     * key.
     *
     * @return void
     */
    public function testExtractDropsEmptyOrderColumns(): void
    {
        $input = ['order' => ',name,:desc,'];

        $parameters = $this->extractor->extract($input);

        self::assertSame(['name' => 'asc'], $parameters['order']);
        self::assertArrayNotHasKey('', $parameters['order']);
    }

    /**
     * Provide order directions that are neither asc nor desc.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unpermittedDirectionProvider(): iterable
    {
        yield 'empty direction' => ['name:', ''];
        yield 'unknown direction' => ['name:sideways', 'sideways'];
        yield 'padded unknown direction' => ['name: SIDEWAYS ', 'SIDEWAYS'];
        yield 'direction containing colons' => ['name:desc:extra', 'desc:extra'];
    }

    /**
     * Test that a direction other than asc or desc is rejected on its key.
     *
     * @param  string  $orderString
     * @param  string  $direction
     * @return void
     */
    #[DataProvider('unpermittedDirectionProvider')]
    public function testExtractRejectsAnUnpermittedOrderDirection(string $orderString, string $direction): void
    {
        $input = ['order' => 'email:desc,' . $orderString];

        try {
            $this->extractor->extract($input);
            self::fail('Expected a ValidationException for the order direction.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['order.name' => ['The "' . $direction . '" direction is not permitted on the "name" key, which accepts asc, desc.']],
                $exception->errors(),
            );
        }
    }

    /**
     * Test that a search term is normalised into a parsed term.
     *
     * @return void
     */
    public function testExtractParsesTheSearchTerm(): void
    {
        $input = ['search' => '  john   smith  '];

        $parameters = $this->extractor->extract($input);

        self::assertInstanceOf(SearchTerm::class, $parameters['search']);
        self::assertSame('john smith', $parameters['search']->value());
    }

    /**
     * Test that a search term outside its bounds is rejected rather than
     * trimmed.
     *
     * @return void
     */
    public function testExtractRejectsASearchTermBelowTheMinimumLength(): void
    {
        $input = ['search' => 'sm'];

        try {
            $this->extractor->extract($input);
            self::fail('Expected a ValidationException for the search parameter.');
        } catch (ValidationException $exception) {
            self::assertSame(['search' => ['Every word in the search term must be at least 3 characters.']], $exception->errors());
        }
    }

    /**
     * Provide search values that are not text.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonTextSearchProvider(): iterable
    {
        yield 'list' => [['smith']];
        yield 'null' => [null];
        yield 'integer' => [42];
        yield 'boolean' => [true];
    }

    /**
     * Test that a search parameter that is not text is read as an empty term
     * and rejected.
     *
     * @param  mixed  $search
     * @return void
     */
    #[DataProvider('nonTextSearchProvider')]
    public function testExtractRejectsANonTextSearchParameter(mixed $search): void
    {
        $input = ['search' => $search];

        try {
            $this->extractor->extract($input);
            self::fail('Expected a ValidationException for the search parameter.');
        } catch (ValidationException $exception) {
            self::assertSame(['search' => ['Every word in the search term must be at least 3 characters.']], $exception->errors());
        }
    }

    /**
     * Test that extract parses multiple parameters simultaneously.
     *
     * @return void
     */
    public function testExtractParsesMultipleParametersSimultaneously(): void
    {
        $input = [
            'fields'  => ['users' => 'name,email'],
            'order'   => 'name:asc',
            'page'    => '2',
            'limit'   => '10',
            'filters' => json_encode(['active' => true]),
        ];

        $parameters = $this->extractor->extract($input);

        self::assertSame(['users' => ['name', 'email']], $parameters['fields']);
        self::assertSame(['name' => 'asc'], $parameters['order']);
        self::assertSame('2', $parameters['page']);
        self::assertSame('10', $parameters['limit']);
        self::assertSame(['active' => true], $parameters['filters']);
    }

    /**
     * Assert that extracting the request rejects the filters parameter.
     *
     * @param  array<string, mixed>  $input
     * @return void
     */
    private function assertRejectsFilters(array $input): void
    {
        try {
            $this->extractor->extract($input);
            self::fail('Expected a ValidationException for the filters parameter.');
        } catch (ValidationException $exception) {
            self::assertSame(['filters' => ['The filters parameter must be a JSON object.']], $exception->errors());
        }
    }
}
