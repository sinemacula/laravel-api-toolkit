<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Schema\Introspection\ColumnDefinition;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Tests for the ColumnLiteral concern class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @SuppressWarnings("php:S1192")
 *
 * @internal
 */
#[CoversClass(ColumnLiteral::class)]
final class ColumnLiteralTest extends TestCase
{
    /** @var string */
    private const string UUID = '123e4567-e89b-12d3-a456-426614174000';

    /** @var string */
    private const string INT2 = 'an integer from -32768 to 32767';

    /** @var string */
    private const string INT4 = 'an integer from -2147483648 to 2147483647';

    /** @var string */
    private const string INT8 = 'an integer from -9223372036854775808 to 9223372036854775807';

    /** @var string */
    private const string NUMBER = 'a finite number';

    /** @var string */
    private const string SINGLE = 'a number within single-precision range';

    /** @var string */
    private const string BOOLEAN = 'true, false, 0 or 1';

    /** @var string */
    private const string DATE = 'a date as YYYY-MM-DD';

    /** @var string */
    private const string DATE_TIME = 'a date-time as YYYY-MM-DDTHH:MM[:SS[.fraction]], or with a space in place of the T, '
        . 'optionally ending in Z or an offset such as +02 or +02:00, or a date as YYYY-MM-DD';

    /** @var string|null */
    private ?string $timezone = null;

    /**
     * Restore the default timezone a test changed.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->timezone !== null) {
            date_default_timezone_set($this->timezone);
        }

        parent::tearDown();
    }

    /**
     * Provide values each column type compares, by catalogue type name.
     *
     * @return iterable<string, array{string, bool|float|int|string}>
     */
    public static function fittingValueProvider(): iterable
    {
        yield 'int4 int' => ['int4', 5];
        yield 'int4 lowest digit string' => ['int4', '-2147483648'];
        yield 'int4 highest digit string' => ['int4', '2147483647'];
        yield 'int4 signed digit string' => ['int4', '+7'];
        yield 'int4 zero-padded digit string' => ['int4', '0000000002147483647'];
        yield 'int4 bool' => ['int4', false];
        yield 'int2 lowest' => ['int2', -32768];
        yield 'int2 highest' => ['int2', 32767];
        yield 'int2 negative zero' => ['int2', '-0'];
        yield 'int8 highest digit string' => ['int8', '9223372036854775807'];
        yield 'int8 lowest digit string' => ['int8', '-9223372036854775808'];
        yield 'int8 highest int' => ['int8', PHP_INT_MAX];
        yield 'bigint past int64' => ['bigint', '18446744073709551615'];
        yield 'tinyint bool' => ['tinyint', true];
        yield 'integer int' => ['integer', 3];
        yield 'smallint int' => ['smallint', 3];
        yield 'mediumint int' => ['mediumint', 3];
        yield 'int int' => ['int', 3];
        yield 'float8 float' => ['float8', 1.5];
        yield 'float8 largest double' => ['float8', '1.7976931348623157e308'];
        yield 'float8 zero string' => ['float8', '0'];
        yield 'float8 zero with an exponent' => ['float8', '0e-400'];
        yield 'float8 zero float' => ['float8', 0.0];
        yield 'numeric fraction' => ['numeric', '-.5'];
        yield 'decimal int' => ['decimal', 10];
        yield 'decimal zero int' => ['decimal', 0];
        yield 'double exponent' => ['double', '1e5'];
        yield 'real float' => ['real', 2.5];
        yield 'float float' => ['float', 2.5];
        yield 'float4 largest single' => ['float4', '3.4028234e38'];
        yield 'float4 smallest subnormal' => ['float4', 1.4e-45];
        yield 'bool true' => ['bool', true];
        yield 'boolean false' => ['boolean', false];
        yield 'boolean 0' => ['boolean', 0];
        yield 'boolean 1' => ['boolean', 1];
        yield 'boolean "0"' => ['boolean', '0'];
        yield 'boolean "1"' => ['boolean', '1'];
        yield 'date leap day' => ['date', '2024-02-29'];
        yield 'date month end' => ['date', '2024-01-31'];
        yield 'datetime date' => ['datetime', '2024-01-01'];
        yield 'datetime minutes' => ['datetime', '2024-01-01T10:00'];
        yield 'datetime space' => ['datetime', '2024-01-01 10:00:00'];
        yield 'datetime last second of a month' => ['datetime', '2024-01-31T23:59:59'];
        yield 'timestamp fraction in UTC' => ['timestamp', '2024-01-01T10:00:00.123456789Z'];
        yield 'timestamptz furthest offset' => ['timestamptz', '2024-01-01T23:59:59+14:00'];
        yield 'timestamptz half-hour offset' => ['timestamptz', '2024-01-01T10:00-05:30'];
        yield 'timestamptz last offset minute' => ['timestamptz', '2024-01-01T10:00+05:59'];
        yield 'timestamptz hour offset as the engine writes it' => ['timestamptz', '2024-01-01 10:00:00.123456+00'];
        yield 'uuid' => ['uuid', self::UUID];
        yield 'varchar string' => ['varchar', 'abc'];
        yield 'varchar int' => ['varchar', 5];
        yield 'text float' => ['text', 1.5];
        yield 'time string' => ['time', '10:00'];
    }

    /**
     * Test that a value its column type compares is accepted.
     *
     * @param  string  $type
     * @param  bool|float|int|string  $value
     * @return void
     */
    #[DataProvider('fittingValueProvider')]
    public function testValueTheColumnTypeComparesIsAccepted(string $type, bool|float|int|string $value): void
    {
        self::assertNull($this->literal($type)->mismatch(new User, 'value', $value));
    }

    /**
     * Provide values each column type does not compare, with the expectation
     * each is refused with.
     *
     * @return iterable<string, array{string, bool|float|int|string, string}>
     */
    public static function mismatchedValueProvider(): iterable
    {
        yield 'int4 float' => ['int4', 1.0, self::INT4];
        yield 'int4 word' => ['int4', 'abc', self::INT4];
        yield 'int4 digits then a word' => ['int4', '1abc', self::INT4];
        yield 'int4 decimal string' => ['int4', '1.5', self::INT4];
        yield 'int4 empty string' => ['int4', '', self::INT4];
        yield 'int4 above the range' => ['int4', '2147483648', self::INT4];
        yield 'int4 below the range' => ['int4', '-2147483649', self::INT4];
        yield 'int4 same length above the range' => ['int4', '2147483657', self::INT4];
        yield 'int4 int above the range' => ['int4', 3000000000, self::INT4];
        yield 'int2 above the range' => ['int2', 32768, self::INT2];
        yield 'int2 below the range' => ['int2', -32769, self::INT2];
        yield 'int8 past int64' => ['int8', '9223372036854775808', self::INT8];
        yield 'int8 below int64' => ['int8', '-9223372036854775809', self::INT8];
        yield 'int8 float past int64' => ['int8', 1e19, self::INT8];
        yield 'bigint float' => ['bigint', 2.0, 'an integer'];
        yield 'float8 infinite' => ['float8', INF, self::NUMBER];
        yield 'float8 overflowing string' => ['float8', '1e400', self::NUMBER];
        yield 'float8 underflowing string' => ['float8', '1e-400', self::NUMBER];
        yield 'numeric overflowing string' => ['numeric', '1e200000', self::NUMBER];
        yield 'decimal word' => ['decimal', 'abc', self::NUMBER];
        yield 'double bool' => ['double', true, self::NUMBER];
        yield 'float4 overflowing string' => ['float4', '1e39', self::SINGLE];
        yield 'float4 overflowing float' => ['float4', 1e39, self::SINGLE];
        yield 'float4 underflowing string' => ['float4', '1e-46', self::SINGLE];
        yield 'float4 underflowing float' => ['float4', 1e-46, self::SINGLE];
        yield 'boolean word' => ['boolean', 'true', self::BOOLEAN];
        yield 'bool two' => ['bool', 2, self::BOOLEAN];
        yield 'date past the month end' => ['date', '2024-02-30', self::DATE];
        yield 'date leap day of a common year' => ['date', '2023-02-29', self::DATE];
        yield 'date with a time' => ['date', '2024-01-01T00:00', self::DATE];
        yield 'date with a prefix' => ['date', 'x2024-01-01', self::DATE];
        yield 'date with a suffix' => ['date', '2024-01-01x', self::DATE];
        yield 'date int' => ['date', 20240101, self::DATE];
        yield 'date with a trailing newline' => ['date', "2024-01-01\n", self::DATE];
        yield 'datetime past the month end' => ['datetime', '2024-02-30T10:00', self::DATE_TIME];
        yield 'datetime month 13' => ['datetime', '2024-13-01', self::DATE_TIME];
        yield 'datetime slashed date' => ['datetime', '2024/01/01 10:00', self::DATE_TIME];
        yield 'datetime time alone' => ['datetime', '10:00:00', self::DATE_TIME];
        yield 'datetime fraction without seconds' => ['datetime', '2024-01-01T10:00.5', self::DATE_TIME];
        yield 'datetime trailing newline' => ['timestamp', "2024-01-01T10:00\n", self::DATE_TIME];
        yield 'datetime hour 24' => ['datetime', '2024-01-01T24:00', self::DATE_TIME];
        yield 'datetime minute 60' => ['datetime', '2024-01-01T10:60', self::DATE_TIME];
        yield 'datetime second 60' => ['datetime', '2024-01-01T10:00:60', self::DATE_TIME];
        yield 'datetime offset past 14 hours' => ['timestamptz', '2024-01-01T10:00+15:00', self::DATE_TIME];
        yield 'datetime offset minute 60' => ['timestamptz', '2024-01-01T10:00+02:60', self::DATE_TIME];
        yield 'datetime hour offset past 14 hours' => ['timestamptz', '2024-01-01T10:00+15', self::DATE_TIME];
        yield 'datetime one-digit offset' => ['timestamptz', '2024-01-01T10:00+2', self::DATE_TIME];
        yield 'datetime zone on a date' => ['timestamp', '2024-01-01Z', self::DATE_TIME];
        yield 'datetime lowercase separator' => ['timestamp', '2024-01-01t10:00', self::DATE_TIME];
        yield 'datetime word' => ['timestamp', 'banana', self::DATE_TIME];
        yield 'datetime int' => ['timestamp', 1704103200, self::DATE_TIME];
        yield 'uuid word' => ['uuid', 'not-a-uuid', 'a UUID'];
        yield 'uuid int' => ['uuid', 5, 'a UUID'];
        yield 'varchar bool' => ['varchar', true, 'a string or a number'];
        yield 'json bool' => ['json', false, 'a string or a number'];
    }

    /**
     * Test that a value its column type does not compare is described by what
     * the column expects.
     *
     * @param  string  $type
     * @param  bool|float|int|string  $value
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('mismatchedValueProvider')]
    public function testValueTheColumnTypeDoesNotCompareIsDescribed(string $type, bool|float|int|string $value, string $expected): void
    {
        self::assertSame($expected, $this->literal($type)->mismatch(new User, 'value', $value));
    }

    /**
     * Provide each checked type name with the form its column expects.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function typeNameProvider(): iterable
    {
        foreach (['int2' => self::INT2, 'int4' => self::INT4, 'int8' => self::INT8] as $type => $expected) {
            yield $type => [$type, $expected];
        }

        foreach (['smallint', 'int', 'integer', 'mediumint', 'bigint', 'tinyint'] as $type) {
            yield $type => [$type, 'an integer'];
        }

        foreach (['decimal', 'numeric', 'float', 'float8', 'double', 'real'] as $type) {
            yield $type => [$type, self::NUMBER];
        }

        foreach (['datetime', 'timestamp', 'timestamptz'] as $type) {
            yield $type => [$type, self::DATE_TIME];
        }

        yield 'float4' => ['float4', self::SINGLE];
        yield 'bool' => ['bool', self::BOOLEAN];
        yield 'boolean' => ['boolean', self::BOOLEAN];
        yield 'date' => ['date', self::DATE];
        yield 'uuid' => ['uuid', 'a UUID'];
    }

    /**
     * Test that every checked type name refuses a word with the form its column
     * expects.
     *
     * @param  string  $type
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('typeNameProvider')]
    public function testEveryCheckedTypeNameRefusesAWord(string $type, string $expected): void
    {
        self::assertSame($expected, $this->literal($type)->mismatch(new User, 'value', 'abc'));
    }

    /**
     * Test that a column the catalogue does not describe is unchecked.
     *
     * @return void
     */
    public function testColumnWithoutADefinitionIsUnchecked(): void
    {
        $literal = $this->literal('int4');

        self::assertNull($literal->mismatch(new User, 'other', true));
        self::assertNull($literal->mismatch(new User, 'other', 'abc'));
    }

    /**
     * Test that the type of a column is its catalogue type name, or null where
     * the catalogue does not describe it.
     *
     * @return void
     */
    public function testTypeOfReadsTheCatalogueTypeName(): void
    {
        $literal = $this->literal('int4');

        self::assertSame('int4', $literal->typeOf(new User, 'value'));
        self::assertNull($literal->typeOf(new User, 'other'));
    }

    /**
     * Test that a NUL character is refused on every column, described or not.
     *
     * @return void
     */
    public function testNulCharacterIsRefusedOnEveryColumn(): void
    {
        $literal = $this->literal('varchar');

        self::assertSame('free of NUL characters', $literal->mismatch(new User, 'value', "a\0b"));
        self::assertSame('free of NUL characters', $literal->mismatch(new User, 'other', "\0"));
    }

    /**
     * Provide cursor boundaries, with whether each column compares it.
     *
     * @return iterable<string, array{string, bool|float|int|string, bool}>
     */
    public static function boundaryProvider(): iterable
    {
        yield 'date written from a date cast' => ['date', '2024-01-01 00:00:00', true];
        yield 'date alone' => ['date', '2024-01-01', true];
        yield 'date-time past the month end on a date' => ['date', '2024-02-30 00:00:00', false];
        yield 'zoned date-time on a date' => ['date', '2024-01-01 00:00:00+00', false];
        yield 'int on a date' => ['date', 20240101, false];
        yield 'date-time on an integer' => ['int4', '2024-01-01 00:00:00', false];
        yield 'int on an integer' => ['int4', 5, true];
        yield 'digit string on an integer' => ['int4', '5', true];
        yield 'bool on an integer' => ['int4', true, true];
        yield 'word on an integer' => ['int4', 'abc', false];
    }

    /**
     * Test that a cursor boundary fits when its column compares it.
     *
     * @param  string  $type
     * @param  bool|float|int|string  $value
     * @param  bool  $fits
     * @return void
     */
    #[DataProvider('boundaryProvider')]
    public function testBoundaryFitsWhenItsColumnComparesIt(string $type, bool|float|int|string $value, bool $fits): void
    {
        self::assertSame($fits, $this->literal($type)->fitsBoundary(new User, 'value', $value));
    }

    /**
     * Provide date-times with the literal each resolves to in Paris time.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function dateTimeResolutionProvider(): iterable
    {
        yield 'UTC instant' => ['datetime', 'Y-m-d H:i:s', '2024-01-01T10:00:00Z', '2024-01-01 11:00:00'];
        yield 'offset instant' => ['timestamp', 'Y-m-d H:i:s', '2024-07-01T10:00:00+02:00', '2024-07-01 10:00:00'];
        yield 'other offset instant' => ['timestamp', 'Y-m-d H:i:s', '2024-01-01T05:30:00-04:00', '2024-01-01 10:30:00'];
        yield 'hour offset instant' => ['timestamp', 'Y-m-d H:i:s', '2024-01-01 10:00:00-05', '2024-01-01 16:00:00'];
        yield 'date alone' => ['datetime', 'Y-m-d H:i:s', '2024-01-01', '2024-01-01 00:00:00'];
        yield 'wall clock' => ['datetime', 'Y-m-d H:i:s', '2024-01-01T10:00', '2024-01-01 10:00:00'];
        yield 'fraction the format drops' => ['datetime', 'Y-m-d H:i:s', '2024-01-01T10:00:00.5', '2024-01-01 10:00:00'];
        yield 'fraction the format keeps' => ['datetime', 'Y-m-d H:i:s.u', '2024-01-01T10:00:00.123456Z', '2024-01-01 11:00:00.123456'];
        yield 'wall clock in a DST gap' => ['datetime', 'Y-m-d H:i:s', '2024-03-31T02:30:00', '2024-03-31 02:30:00'];
        yield 'zoned column keeps the offset' => ['timestamptz', 'Y-m-d H:i:s', '2024-01-01T10:00:00Z', '2024-01-01 11:00:00+01:00'];
        yield 'zoned column reads a wall clock locally' => ['timestamptz', 'Y-m-d H:i:s', '2024-07-01 10:00', '2024-07-01 10:00:00+02:00'];
        yield 'zoned column with a zoned format' => ['timestamptz', 'Y-m-d H:i:sO', '2024-01-01T10:00:00Z', '2024-01-01 11:00:00+0100'];
        yield 'model format carrying an offset' => ['timestamp', 'Y-m-d H:i:sP', '2024-01-01T10:00:00Z', '2024-01-01 11:00:00+01:00'];
        yield 'model format escaping a zone letter' => ['timestamp', 'Y-m-d\TH:i:s', '2024-01-01T10:00:00Z', '2024-01-01T11:00:00'];
        yield 'zoned column with a format escaping a zone letter' => ['timestamptz', 'Y-m-d\TH:i:s', '2024-01-01T10:00', '2024-01-01T10:00:00+01:00'];
        yield 'model format as an epoch' => ['timestamp', 'U', '2024-01-01T10:00:00', '1704099600'];
        yield 'zoned column with an ISO 8601 format' => ['timestamptz', 'c', '2024-01-01T10:00:00Z', '2024-01-01T11:00:00+01:00'];
        yield 'zoned column with an RFC 2822 format' => ['timestamptz', 'r', '2024-01-01T10:00:00Z', 'Mon, 01 Jan 2024 11:00:00 +0100'];
    }

    /**
     * Test that a date-time resolves to the instant in the default timezone, in
     * the model's date format.
     *
     * @param  string  $type
     * @param  string  $format
     * @param  string  $value
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('dateTimeResolutionProvider')]
    public function testDateTimeResolvesToTheModelsFormatInTheDefaultTimezone(string $type, string $format, string $value, string $expected): void
    {
        $this->timezone = date_default_timezone_get();

        date_default_timezone_set('Europe/Paris');

        self::assertSame($expected, $this->literal($type)->resolve((new User)->setDateFormat($format), 'value', $value));
    }

    /**
     * Provide values that are bound as sent, with the column type each meets.
     *
     * @return iterable<string, array{string, string, bool|float|int|string}>
     */
    public static function unresolvedValueProvider(): iterable
    {
        yield 'date' => ['date', 'value', '2024-01-01'];
        yield 'integer digit string' => ['int4', 'value', '007'];
        yield 'varchar' => ['varchar', 'value', '2024-01-01T10:00:00Z'];
        yield 'int on a date-time' => ['timestamp', 'value', 5];
        yield 'undescribed column' => ['timestamp', 'other', '2024-01-01T10:00:00Z'];
    }

    /**
     * Test that a value other than a date-time is bound as sent.
     *
     * @param  string  $type
     * @param  string  $column
     * @param  bool|float|int|string  $value
     * @return void
     */
    #[DataProvider('unresolvedValueProvider')]
    public function testValueOtherThanADateTimeIsBoundAsSent(string $type, string $column, bool|float|int|string $value): void
    {
        self::assertSame($value, $this->literal($type)->resolve(new User, $column, $value));
    }

    /**
     * Build the resolver over a catalogue describing one column of the type.
     *
     * @param  string  $type
     * @return \SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral
     */
    private function literal(string $type): ColumnLiteral
    {
        $introspector = self::createStub(SchemaIntrospectionProvider::class);
        $introspector->method('getColumnDefinitions')->willReturn(['value' => new ColumnDefinition('value', $type, false)]);

        return new ColumnLiteral($introspector);
    }
}
