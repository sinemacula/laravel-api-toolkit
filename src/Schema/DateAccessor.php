<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Renders the date an attribute holds, whether cast or a date string.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class DateAccessor
{
    /**
     * Create a new date accessor.
     *
     * @param  string  $attribute
     * @param  bool  $dateOnly
     */
    private function __construct(

        /** The attribute path the date is read from */
        public string $attribute,

        /** Whether the time of day is dropped */
        private bool $dateOnly,
    ) {}

    /**
     * Render the attribute's date, or null when the attribute is null.
     *
     * @param  mixed  $resource
     * @return string|null
     *
     * @throws \Carbon\Exceptions\InvalidFormatException
     */
    public function __invoke(mixed $resource): ?string
    {
        $value = data_get($resource, $this->attribute);

        if ($value === null) {
            return null;
        }

        $date = Carbon::make($value) ?? throw new InvalidFormatException(sprintf('The "%s" attribute holds no date', $this->attribute));

        return $this->dateOnly ? $date->toDateString() : $date->toIso8601String();
    }

    /**
     * Build an accessor rendering the attribute as an ISO 8601 date-time.
     *
     * @param  string  $attribute
     * @return self
     */
    public static function timestamp(string $attribute): self
    {
        return new self($attribute, false);
    }

    /**
     * Build an accessor rendering the attribute as a date.
     *
     * @param  string  $attribute
     * @return self
     */
    public static function date(string $attribute): self
    {
        return new self($attribute, true);
    }
}
