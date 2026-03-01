<?php

namespace SineMacula\ApiToolkit\Http\Resources\Schema;

use Closure;

/**
 * Typed representation of a single compiled field definition.
 *
 * Replaces the untyped associative arrays previously used in the schema cache,
 * providing typed access to all resolved field properties.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class CompiledFieldDefinition
{
    /**
     * Create a new compiled field definition.
     *
     * @param  mixed  $accessor
     * @param  mixed  $compute
     * @param  string|null  $relation
     * @param  string|null  $resource
     * @param  array<int, string>|null  $fields
     * @param  \Closure(mixed): mixed|null  $constraint
     * @param  array<int, string>  $extras
     * @param  array<int, callable(mixed, mixed): bool>  $guards
     * @param  array<int, callable(mixed, mixed): mixed>  $transformers
     */
    public function __construct(

        /** Path or callable for value access */
        public mixed $accessor,

        /** Method name or callable for computed values */
        public mixed $compute,

        /** The Eloquent relation name */
        public ?string $relation,

        /** The child resource class for relation wrapping */
        public ?string $resource,

        /** Explicit child field list for the relation */
        public ?array $fields,

        /** Optional query constraint for eager loading */
        public ?Closure $constraint,

        /** Additional eager-load paths */
        public array $extras,

        /** Guard closures that control visibility */
        public array $guards,

        /** Value transformer closures */
        public array $transformers,

    ) {}
}
