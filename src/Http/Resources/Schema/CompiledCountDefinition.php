<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

use Closure;

/**
 * Typed representation of a single compiled count definition.
 *
 * Replaces the untyped associative arrays previously used in the schema cache,
 * providing typed access to all resolved count properties.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CompiledCountDefinition
{
    /**
     * Create a new compiled count definition.
     *
     * @param  string  $presentKey
     * @param  string  $relation
     * @param  \Closure(mixed): mixed|null  $constraint
     * @param  bool  $isDefault
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     */
    public function __construct(

        /** The key used in the JSON response */
        public string $presentKey,

        /** The Eloquent relation to count */
        public string $relation,

        /** Optional query constraint for the count */
        public ?Closure $constraint,

        /** Whether this count is included by default */
        public bool $isDefault,

        /** Guard closures that control visibility */
        public array $guards,

    ) {}
}
