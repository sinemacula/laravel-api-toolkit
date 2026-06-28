<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Contracts\ServiceInput;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;

/**
 * ServiceInput fixture covering every primitive PHP type and an enum property.
 *
 * Used by RuleCompilerTest to exercise the type-mapping branches.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AllTypesInput implements ServiceInput
{
    /**
     * Create a new AllTypesInput fixture instance.
     *
     * @param  string  $name
     * @param  int  $age
     * @param  float  $score
     * @param  bool  $active
     * @param  array<int, mixed>  $tags
     * @param  string|null  $nullable
     * @param  \Tests\Fixtures\Services\Input\Enums\StubStatusEnum  $status
     */
    public function __construct(

        /** String property. */
        public readonly string $name = '',

        /** Integer property. */
        public readonly int $age = 0,

        /** Float property. */
        public readonly float $score = 0.0,

        /** Boolean property. */
        public readonly bool $active = false,

        /** Array property. */
        public readonly array $tags = [],

        /** Nullable string property. */
        public readonly ?string $nullable = null,

        /** Backed enum property. */
        public readonly StubStatusEnum $status = StubStatusEnum::ACTIVE,
    ) {}

    /**
     * Return the input as an associative array.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [];
    }
}
