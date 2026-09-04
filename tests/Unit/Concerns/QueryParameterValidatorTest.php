<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException;
use SineMacula\ApiToolkit\Query\QueryCostLimits;
use Tests\TestCase;

/**
 * Tests for the QueryParameterValidator concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(QueryParameterValidator::class)]
final class QueryParameterValidatorTest extends TestCase
{
    /** @var string A well-formed filter document used to pin the byte cap */
    private const string FILTER_DOCUMENT = '{"status":{"$eq":"active"}}';

    /** @var string The configuration key holding the page-size ceiling */
    private const string MAX_LIMIT_KEY = 'api-toolkit.parser.max_limit';

    /** @var \SineMacula\ApiToolkit\Concerns\QueryParameterValidator */
    private QueryParameterValidator $validator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new QueryParameterValidator;
    }

    /**
     * Provide parameter sets that pass validation.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function validParameterProvider(): iterable
    {
        yield 'no parameters' => [[]];
        yield 'fields string' => [['fields' => 'name,email']];
        yield 'fields array of strings' => [['fields' => ['user' => 'name,email']]];
        yield 'counts array of strings' => [['counts' => ['user' => 'posts,comments']]];
        yield 'sums nested arrays of strings' => [['sums' => ['account' => ['transaction' => 'amount']]]];
        yield 'averages nested arrays of strings' => [['averages' => ['account' => ['transaction' => 'amount']]]];
        yield 'valid json filters' => [['filters' => '{"status":"active"}']];
        yield 'order string' => [['order' => 'name:asc']];
        yield 'page of one' => [['page' => '1']];
        yield 'limit of one' => [['limit' => '1']];
        yield 'cursor string' => [['cursor' => 'eyJpZCI6MTAwfQ==']];
        yield 'search string' => [['search' => 'john smith']];
    }

    /**
     * Test that valid parameters pass validation without an exception.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('validParameterProvider')]
    public function testValidParametersPassValidation(array $parameters): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate($parameters);
    }

    /**
     * Provide parameter sets that fail validation.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidParameterProvider(): iterable
    {
        yield 'non-numeric page' => [['page' => 'not-a-number']];
        yield 'page below one' => [['page' => '0']];
        yield 'non-numeric limit' => [['limit' => 'abc']];
        yield 'limit below one' => [['limit' => '0']];
        yield 'invalid json filters' => [['filters' => 'not-valid-json{']];
        yield 'integer fields' => [['fields' => 123]];
        yield 'array order' => [['order' => ['name' => 'asc']]];
        yield 'array cursor' => [['cursor' => ['id' => 100]]];
        yield 'array search' => [['search' => ['smith']]];
        yield 'integer search' => [['search' => 42]];
        yield 'array fields resource value' => [['fields' => ['user' => ['name', 'email']]]];
        yield 'array counts resource value' => [['counts' => ['user' => ['posts', 'comments']]]];
        yield 'string sums resource value' => [['sums' => ['account' => 'amount']]];
        yield 'string averages resource value' => [['averages' => ['account' => 'amount']]];
        yield 'integer sums field value' => [['sums' => ['account' => ['transaction' => 42]]]];
        yield 'integer averages field value' => [['averages' => ['account' => ['transaction' => 42]]]];
    }

    /**
     * Test that invalid parameters fail validation with an exception.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('invalidParameterProvider')]
    public function testInvalidParametersFailValidation(array $parameters): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate($parameters);
    }

    /**
     * Test that a filter document of exactly the byte cap is accepted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilterDocumentAtTheByteCapIsAccepted(): void
    {
        Config::set('api-toolkit.query_cost.max_bytes', strlen(self::FILTER_DOCUMENT));

        $this->expectNotToPerformAssertions();

        $this->validator->validate(['filters' => self::FILTER_DOCUMENT]);
    }

    /**
     * Test that a filter document one byte over the cap is rejected, reporting
     * the cap and the size supplied.
     *
     * @return void
     */
    public function testFilterDocumentOverTheByteCapIsRejected(): void
    {
        $size = strlen(self::FILTER_DOCUMENT);

        Config::set('api-toolkit.query_cost.max_bytes', $size - 1);

        $this->assertRejectedForCost(
            ['filters' => self::FILTER_DOCUMENT],
            'filters',
            QueryCostLimits::MAX_BYTES,
            $size - 1,
            $size,
        );
    }

    /**
     * Test that a filter document nested to exactly the parse-depth cap is
     * accepted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testFilterDocumentAtTheParseDepthCapIsAccepted(): void
    {
        Config::set('api-toolkit.query_cost.max_parse_depth', 3);

        $this->expectNotToPerformAssertions();

        $this->validator->validate(['filters' => '{"posts":{"title":{"$eq":"test"}}}']);
    }

    /**
     * Test that a filter document one level deeper than the parse-depth cap is
     * rejected, measured across the whole document rather than its first
     * branch.
     *
     * @return void
     */
    public function testFilterDocumentOverTheParseDepthCapIsRejected(): void
    {
        Config::set('api-toolkit.query_cost.max_parse_depth', 3);

        $this->assertRejectedForCost(
            ['filters' => '{"name":"Alice","posts":{"tags":{"name":{"$eq":"test"}}}}'],
            'filters',
            QueryCostLimits::MAX_PARSE_DEPTH,
            3,
            4,
        );
    }

    /**
     * Test that a malformed filter document within the byte cap keeps its own
     * validation failure rather than being reported as a cost.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testMalformedFilterDocumentStillFailsValidation(): void
    {
        Config::set('api-toolkit.query_cost.max_bytes', 1024);
        Config::set('api-toolkit.query_cost.max_parse_depth', 1);

        $this->expectException(ValidationException::class);

        $this->validator->validate(['filters' => '{not-valid-json']);
    }

    /**
     * Test that a non-string filters value is left to the shape rules, so the
     * cost guards never assume a decodable document.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testNonStringFilterValueIsLeftToTheShapeRules(): void
    {
        Config::set('api-toolkit.query_cost.max_bytes', 1);
        Config::set('api-toolkit.query_cost.max_parse_depth', 1);

        $this->expectException(ValidationException::class);

        $this->validator->validate(['filters' => ['status' => 'active']]);
    }

    /**
     * Test that a page size of exactly the configured ceiling is accepted.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testPageSizeAtTheCeilingIsAccepted(): void
    {
        Config::set(self::MAX_LIMIT_KEY, 100);

        $this->expectNotToPerformAssertions();

        $this->validator->validate(['limit' => '100']);
    }

    /**
     * Test that a page size one above the ceiling is rejected rather than
     * reduced to it, reporting the ceiling and the size asked for.
     *
     * @return void
     */
    public function testPageSizeOverTheCeilingIsRejected(): void
    {
        Config::set(self::MAX_LIMIT_KEY, 100);

        $this->assertRejectedForCost(['limit' => '101'], 'limit', 'max_limit', 100, 101);
    }

    /**
     * Test that a ceiling configured as a numeric string is coerced before the
     * comparison, so the rejection reports an integer rather than the raw
     * configured value.
     *
     * @return void
     */
    public function testNumericStringCeilingIsCoercedBeforeTheComparison(): void
    {
        Config::set(self::MAX_LIMIT_KEY, '100');

        $this->assertRejectedForCost(['limit' => '101'], 'limit', 'max_limit', 100, 101);
    }

    /**
     * Provide the ceiling values that disable the page-size bound.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function disabledCeilingProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'null' => [null];
        yield 'non-numeric' => ['not-a-number'];
    }

    /**
     * Test that a disabled ceiling leaves the page size unbounded rather than
     * rejecting every request.
     *
     * @param  mixed  $ceiling
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('disabledCeilingProvider')]
    public function testPageSizeIsUnboundedWhenTheCeilingIsDisabled(mixed $ceiling): void
    {
        Config::set(self::MAX_LIMIT_KEY, $ceiling);

        $this->expectNotToPerformAssertions();

        $this->validator->validate(['limit' => '100000']);
    }

    /**
     * Test that a malformed page size keeps its own validation failure rather
     * than being reported as a cost.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testMalformedPageSizeStillFailsValidation(): void
    {
        Config::set(self::MAX_LIMIT_KEY, 1);

        $this->expectException(ValidationException::class);

        $this->validator->validate(['limit' => 'abc']);
    }

    /**
     * Assert that the given parameters are rejected on cost, naming the
     * parameter at fault and carrying the cap that rejected them alongside both
     * sides of the comparison.
     *
     * @param  array<string, mixed>  $parameters
     * @param  string  $parameter
     * @param  string  $reason
     * @param  int  $limit
     * @param  int  $actual
     * @return void
     */
    private function assertRejectedForCost(array $parameters, string $parameter, string $reason, int $limit, int $actual): void
    {
        try {
            $this->validator->validate($parameters);

            self::fail('Expected a rejection for the "' . $reason . '" cap.');
        } catch (QueryTooExpensiveException $exception) {
            self::assertSame([
                'parameter' => $parameter,
                'pointer'   => '',
                'reason'    => $reason,
                'limit'     => $limit,
                'actual'    => $actual,
            ], $exception->getCustomMeta());
        }
    }
}
