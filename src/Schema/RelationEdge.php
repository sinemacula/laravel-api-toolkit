<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

/**
 * Immutable description of one traversable or sortable relation of a resource.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RelationEdge
{
    /**
     * Create a new relation edge.
     *
     * @param  string  $relation
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  bool  $polymorphic
     * @param  class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>|null  $resource
     */
    public function __construct(

        /** The relation name a filter descends or an order reads through */
        public string $relation,

        /** The related model the relation reaches */
        public string $model,

        /** Whether the relation is a MorphTo, governed per concrete type */
        public bool $polymorphic,

        /** The resource governing the related level, or null when per type or none */
        public ?string $resource,
    ) {}
}
