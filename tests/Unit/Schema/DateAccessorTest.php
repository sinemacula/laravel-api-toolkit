<?php

declare(strict_types = 1);

namespace Tests\Unit\Schema;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Schema\DateAccessor;

/**
 * Tests for the DateAccessor field accessor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(DateAccessor::class)]
final class DateAccessorTest extends TestCase
{
    /**
     * Test that a timestamp renders a cast date, a plain date-time or a date
     * string as ISO 8601, keeping its offset.
     *
     * @param  \DateTimeInterface|string  $value
     * @return void
     */
    #[TestWith([new Carbon('2026-07-15T09:30:00+02:00')])]
    #[TestWith([new CarbonImmutable('2026-07-15T09:30:00+02:00')])]
    #[TestWith([new \DateTimeImmutable('2026-07-15T09:30:00+02:00')])]
    #[TestWith(['2026-07-15T09:30:00.123456+02:00'])]
    public function testTimestampRendersADateAsIso8601(\DateTimeInterface|string $value): void
    {
        self::assertSame('2026-07-15T09:30:00+02:00', DateAccessor::timestamp('created_at')(['created_at' => $value]));
    }

    /**
     * Test that a timestamp reads a zone-less date string in the default
     * timezone, as a date cast does.
     *
     * @return void
     */
    public function testTimestampReadsAZonelessStringInTheDefaultTimezone(): void
    {
        $timezone = date_default_timezone_get();

        date_default_timezone_set('Europe/London');

        try {
            self::assertSame('2026-07-15T09:30:00+01:00', DateAccessor::timestamp('created_at')(['created_at' => '2026-07-15 09:30:00']));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * Test that a date renders a cast date, a date string or a date-time string
     * as its calendar date.
     *
     * @param  \DateTimeInterface|string  $value
     * @return void
     */
    #[TestWith([new Carbon('2026-07-15T23:30:00+02:00')])]
    #[TestWith(['2026-07-15'])]
    #[TestWith(['2026-07-15 23:30:00'])]
    public function testDateRendersTheCalendarDate(\DateTimeInterface|string $value): void
    {
        self::assertSame('2026-07-15', DateAccessor::date('born_on')(['born_on' => $value]));
    }

    /**
     * Test that a null attribute renders null.
     *
     * @return void
     */
    public function testANullAttributeRendersNull(): void
    {
        self::assertNull(DateAccessor::timestamp('created_at')(['created_at' => null]));
        self::assertNull(DateAccessor::date('born_on')([]));
    }

    /**
     * Test that the attribute is read by path, through a nested record.
     *
     * @return void
     */
    public function testReadsTheAttributeByPath(): void
    {
        $accessor = DateAccessor::timestamp('pivot.created_at');

        self::assertSame('pivot.created_at', $accessor->attribute);
        self::assertSame('2026-07-15T09:30:00+00:00', $accessor(['pivot' => ['created_at' => '2026-07-15T09:30:00+00:00']]));
    }

    /**
     * Test that a string that does not parse as a date is refused rather than
     * rendered as null.
     *
     * @return void
     */
    public function testRefusesAStringThatDoesNotParse(): void
    {
        $this->expectException(InvalidFormatException::class);

        DateAccessor::date('born_on')(['born_on' => 'not-a-date']);
    }

    /**
     * Test that a value that is neither a date nor a date string is refused,
     * naming the attribute.
     *
     * @param  mixed  $value
     * @return void
     */
    #[TestWith([1752571800])]
    #[TestWith([''])]
    #[TestWith(['P1D'])]
    public function testRefusesAValueThatHoldsNoDate(mixed $value): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('The "created_at" attribute holds no date');

        DateAccessor::timestamp('created_at')(['created_at' => $value]);
    }
}
