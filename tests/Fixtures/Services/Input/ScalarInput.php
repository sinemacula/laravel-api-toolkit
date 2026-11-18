<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Payload fixture hydrating int, float and bool properties.
 *
 * Its rules admit any value, so the payload's own scalar cast alone decides
 * what each property receives.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ScalarInput extends Payload
{
    /**
     * Create a new ScalarInput instance.
     *
     * @param  int|null  $count
     * @param  float|null  $ratio
     * @param  bool|null  $enabled
     */
    public function __construct(

        /** An int property. */
        public readonly ?int $count = null,

        /** A float property. */
        public readonly ?float $ratio = null,

        /** A bool property. */
        public readonly ?bool $enabled = null,
    ) {}

    /**
     * Return the Laravel validation rules for this input.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [
            'count'   => ['sometimes'],
            'ratio'   => ['sometimes'],
            'enabled' => ['sometimes'],
        ];
    }
}
