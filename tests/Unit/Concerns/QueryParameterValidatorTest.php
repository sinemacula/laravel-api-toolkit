<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
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
        yield 'fields array of strings' => [['fields' => ['user' => 'name,email']]];
        yield 'null fields leaf' => [['fields' => ['user' => null]]];
        yield 'empty fields leaf' => [['fields' => ['user' => '']]];
        yield 'aggregates array of strings' => [['aggregates' => ['user' => 'posts_count,orders_sum_total']]];
        yield 'null aggregates leaf' => [['aggregates' => ['user' => null]]];
        yield 'empty aggregates leaf' => [['aggregates' => ['user' => '']]];
        yield 'valid json filters' => [['filters' => '{"status":"active"}']];
        yield 'order string' => [['order' => 'name:asc']];
        yield 'page of one' => [['page' => '1']];
        yield 'limit of one' => [['limit' => '1']];
        yield 'cursor string' => [['cursor' => 'eyJpZCI6MTAwfQ==']];
        yield 'search string' => [['search' => 'john smith']];
        yield 'trashed with' => [['trashed' => 'with']];
        yield 'trashed only' => [['trashed' => 'only']];
        yield 'cursor pagination' => [['pagination' => 'cursor']];
        yield 'null pagination' => [['pagination' => null]];
        yield 'empty pagination' => [['pagination' => '']];
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
        yield 'array order' => [['order' => ['name' => 'asc']]];
        yield 'array cursor' => [['cursor' => ['id' => 100]]];
        yield 'array search' => [['search' => ['smith']]];
        yield 'integer search' => [['search' => 42]];
        yield 'array fields resource value' => [['fields' => ['user' => ['name', 'email']]]];
        yield 'array aggregates resource value' => [['aggregates' => ['user' => ['posts_count', 'orders_sum_total']]]];
        yield 'misspelt pagination' => [['pagination' => 'curser']];
        yield 'capitalised pagination' => [['pagination' => 'Cursor']];
        yield 'list pagination' => [['pagination' => ['cursor']]];
        yield 'malformed pagination' => [['pagination' => "\xFF"]];
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
     * Provide selection parameters that are not keyed by resource type.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function unkeyedSelectionProvider(): iterable
    {
        yield 'flat fields' => ['fields', 'name,email'];
        yield 'integer fields' => ['fields', 123];
        yield 'null fields' => ['fields', null];
        yield 'empty fields' => ['fields', ''];
        yield 'flat aggregates' => ['aggregates', 'posts_count'];
        yield 'integer aggregates' => ['aggregates', 5];
        yield 'null aggregates' => ['aggregates', null];
        yield 'empty aggregates' => ['aggregates', ''];
    }

    /**
     * Test that a selection parameter not keyed by resource type is refused on
     * the parameter, naming the expected form.
     *
     * @param  string  $parameter
     * @param  mixed  $value
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('unkeyedSelectionProvider')]
    public function testUnkeyedSelectionIsRefusedOnTheParameter(string $parameter, mixed $value): void
    {
        $example = $parameter === 'fields' ? 'fields[users]=name' : 'aggregates[users]=posts_count';

        try {
            $this->validator->validate([$parameter => $value]);
            self::fail('An unkeyed selection passed validation.');
        } catch (ValidationException $exception) {
            self::assertSame([$parameter => [sprintf('The %s parameter must be keyed by resource type, e.g. %s.', $parameter, $example)]], $exception->errors());
        }
    }

    /**
     * Provide trashed values the parameter does not accept.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTrashedProvider(): iterable
    {
        yield 'capitalised' => ['With'];
        yield 'upper case' => ['ONLY'];
        yield 'misspelt' => ['onyl'];
        yield 'unknown' => ['deleted'];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'list' => [['only']];
        yield 'empty list' => [[]];
    }

    /**
     * Test that a trashed value other than exactly with or only is refused on
     * the trashed key.
     *
     * @param  mixed  $trashed
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('invalidTrashedProvider')]
    public function testInvalidTrashedValueIsRefusedOnTheTrashedKey(mixed $trashed): void
    {
        try {
            $this->validator->validate(['trashed' => $trashed]);
        } catch (ValidationException $exception) {
            self::assertSame(['trashed'], array_keys($exception->errors()));

            return;
        }

        self::fail('An invalid trashed value passed validation.');
    }

    /**
     * Provide each parameter the parser reads carrying malformed UTF-8.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function malformedEncodingProvider(): iterable
    {
        foreach (['page', 'limit', 'cursor', 'filters', 'search', 'order', 'trashed'] as $parameter) {
            yield $parameter => [$parameter, "n\xFFme"];
        }

        foreach (QueryParameterValidator::SELECTIONS as $parameter) {
            yield $parameter . ' type' => [$parameter, ["\xFF" => 'name']];
            yield $parameter . ' name' => [$parameter, ['users' => "n\xFFme"]];
        }

        yield 'overlong sequence' => ['order', "\xC0\xAF"];
        yield 'encoded surrogate' => ['order', "\xED\xA0\x80"];
        yield 'beyond the last code point' => ['order', "\xF4\x90\x80\x80"];
    }

    /**
     * Test that a parameter the parser reads is refused on the parameter when
     * any key or value in it is not valid UTF-8.
     *
     * @param  string  $parameter
     * @param  mixed  $value
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('malformedEncodingProvider')]
    public function testMalformedEncodingIsRefusedOnTheParameter(string $parameter, mixed $value): void
    {
        try {
            $this->validator->validate([$parameter => $value]);
            self::fail('A malformed parameter passed validation.');
        } catch (ValidationException $exception) {
            self::assertSame([$parameter => [self::malformedEncodingMessage($parameter)]], $exception->errors());
        }
    }

    /**
     * Test that every malformed parameter is named in the one rejection.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testEachMalformedParameterIsNamed(): void
    {
        try {
            $this->validator->validate(['order' => "\xFF", 'page' => '1', 'search' => "\xFF"]);
            self::fail('Malformed parameters passed validation.');
        } catch (ValidationException $exception) {
            self::assertSame([
                'search' => [self::malformedEncodingMessage('search')],
                'order'  => [self::malformedEncodingMessage('order')],
            ], $exception->errors());
        }
    }

    /**
     * Provide parameters the encoding check passes.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function wellFormedEncodingProvider(): iterable
    {
        yield 'multibyte order' => [['order' => 'nämé']];
        yield 'multibyte selection' => [['fields' => ['users' => 'nämé'], 'aggregates' => ['nämé' => null]]];
        yield 'integer page and limit' => [['page' => 2, 'limit' => 10]];
        yield 'application parameters' => [['q' => "\xFF", 'pretty' => "\xFF"]];
    }

    /**
     * Test that well-formed values, integers and parameters the parser does not
     * read pass validation.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('wellFormedEncodingProvider')]
    public function testWellFormedParametersPassTheEncodingCheck(array $parameters): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate($parameters);
    }

    /**
     * Provide values that are not text.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonTextValueProvider(): iterable
    {
        yield 'null' => [null];
        yield 'boolean' => [true];
    }

    /**
     * Test that a value that is not text is left to the shape rules.
     *
     * @param  mixed  $value
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('nonTextValueProvider')]
    public function testNonTextValueIsLeftToTheShapeRules(mixed $value): void
    {
        try {
            $this->validator->validate(['search' => $value]);
            self::fail('A non-text search term passed validation.');
        } catch (ValidationException $exception) {
            self::assertSame(['search' => ['The search field must be a string.']], $exception->errors());
        }
    }

    /**
     * Provide filter documents that are malformed and over the byte cap.
     *
     * @return iterable<string, array{string}>
     */
    public static function oversizedMalformedFilterProvider(): iterable
    {
        yield 'malformed JSON' => ['{' . str_repeat('x', 100)];
        yield 'malformed UTF-8' => ['{"name":"' . str_repeat("\xFF", 100) . '"}'];
    }

    /**
     * Test that a filter document over the byte cap is refused for its size
     * before its shape or encoding is read.
     *
     * @param  string  $filters
     * @return void
     */
    #[DataProvider('oversizedMalformedFilterProvider')]
    public function testOversizedMalformedDocumentIsRefusedForItsSizeBeforeItsShape(string $filters): void
    {
        Config::set('api-toolkit.query_cost.max_bytes', 64);

        $this->assertRejectedForCost(['filters' => $filters], 'filters', QueryCostLimits::MAX_BYTES, 64, strlen($filters));
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
     * Test that a filter document one byte over the cap is rejected with the
     * cap and size.
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
     * Test that a filter document one level past the parse-depth cap is
     * rejected, measured across the whole document.
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
     * validation failure.
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
     * Test that a non-string filters value is left to the shape rules.
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
     * Test that a bare JSON scalar filter document is passed over while a
     * nesting one is still capped.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testScalarFilterDocumentCarriesNoNestingToMeasure(): void
    {
        Config::set('api-toolkit.query_cost.max_parse_depth', 1);

        $this->validator->validate(['filters' => '"active"']);

        $this->assertRejectedForCost(
            ['filters' => self::FILTER_DOCUMENT],
            'filters',
            QueryCostLimits::MAX_PARSE_DEPTH,
            1,
            2,
        );
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
     * Test that a page size one above the ceiling is rejected with the ceiling
     * and size.
     *
     * @return void
     */
    public function testPageSizeOverTheCeilingIsRejected(): void
    {
        Config::set(self::MAX_LIMIT_KEY, 100);

        $this->assertRejectedForCost(['limit' => '101'], 'limit', 'max_limit', 100, 101);
    }

    /**
     * Test that a numeric-string ceiling is reported as an integer.
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
        yield 'zero string' => ['0'];
        yield 'null' => [null];
    }

    /**
     * Test that a disabled ceiling leaves the page size unbounded.
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
     * Provide ceilings that are neither null nor a whole number of at least 0,
     * with how the message names each.
     *
     * @return iterable<string, array{mixed, string}>
     */
    public static function unreadableCeilingProvider(): iterable
    {
        yield 'non-numeric' => ['not-a-number', '\'not-a-number\''];
        yield 'empty environment value' => ['', '\'\''];
        yield 'array' => [[100], 'array'];
        yield 'negative' => [-1, '-1'];
    }

    /**
     * Test that an unreadable ceiling fails rather than falling back to a
     * default or disabling the ceiling.
     *
     * @param  mixed  $ceiling
     * @param  string  $given
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    #[DataProvider('unreadableCeilingProvider')]
    public function testUnreadableCeilingFails(mixed $ceiling, string $given): void
    {
        Config::set(self::MAX_LIMIT_KEY, $ceiling);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(sprintf('The api-toolkit.parser.max_limit setting must be an integer of at least 0, or null; [%s] given.', $given));

        $this->validator->validate(['limit' => '10']);
    }

    /**
     * Test that a config predating the page-size key fails rather than reading
     * as no ceiling.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function testAbsentCeilingKeyFails(): void
    {
        Config::set('api-toolkit.parser', Arr::except(
            (array) Config::get('api-toolkit.parser'),
            ['max_limit'],
        ));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The api-toolkit.parser.max_limit setting is missing; add it from the published config.');

        $this->validator->validate(['limit' => '10']);
    }

    /**
     * Test that a malformed page size keeps its own validation failure.
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
     * Build the message refusing the parameter for malformed UTF-8.
     *
     * @param  string  $parameter
     * @return string
     */
    private static function malformedEncodingMessage(string $parameter): string
    {
        return 'The ' . $parameter . ' parameter must be valid UTF-8.';
    }

    /**
     * Assert that the parameters are rejected on cost with the parameter, cap
     * and both compared values.
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
