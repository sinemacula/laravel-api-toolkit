<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SineMacula\ApiToolkit\Contracts\ApiResourceInterface;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;

/**
 * Resolves the resource governing a filter that descends through a relation.
 *
 * A polymorphic descent is governed by the mapped resource of each concrete
 * type, any other by the resource declared over the relation, else the mapped
 * resource of the related model. An unresolved level governs nothing, so every
 * key under it is refused.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RelationTraversal
{
    /**
     * Create a new relation traversal.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @param  array<string, string>  $resourceMap
     */
    public function __construct(

        /** Resolves a model's relation methods */
        private SchemaIntrospectionProvider $introspector,

        /** Model classes mapped to the resource serving them */
        private array $resourceMap,
    ) {}

    /**
     * Resolve the resource governing the related level of a traversal.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $related
     * @param  bool  $polymorphic
     * @return class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>|null
     */
    public function governingResource(CompiledSchema $schema, string $relation, Model $related, bool $polymorphic): ?string
    {
        $resource = $polymorphic ? null : $schema->getTraversableResource($relation);
        $resource ??= $this->resourceMap[$related::class] ?? null;

        return $resource !== null && is_subclass_of($resource, ApiResourceInterface::class) ? $resource : null;
    }

    /**
     * Resolve each traversable relation of a resource served for a model.
     *
     * A relation the model does not define is skipped.
     *
     * @param  string  $resourceClass
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return list<\SineMacula\ApiToolkit\Schema\RelationEdge>
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function edges(string $resourceClass, string $modelClass): array
    {
        $schema = SchemaCompiler::compile($resourceClass);
        $model  = new $modelClass;
        $edges  = [];

        foreach ($schema->getTraversableRelations() as $name) {

            $relation = $this->introspector->resolveRelation($name, $model);

            if ($relation === null) {
                continue;
            }

            $related     = $relation->getRelated();
            $polymorphic = $relation instanceof MorphTo;

            $edges[] = new RelationEdge(
                relation   : $name,
                model      : $related::class,
                polymorphic: $polymorphic,
                resource   : $polymorphic ? null : $this->governingResource($schema, $name, $related, false),
            );
        }

        return $edges;
    }
}
