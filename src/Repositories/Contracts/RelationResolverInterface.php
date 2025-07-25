<?php

namespace SineMacula\ApiToolkit\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;

interface RelationResolverInterface
{
    /**
     * Determine if this resolver can handle the given relation.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function supports(string $relation, Model $model): bool;

    /**
     * Resolve the relation value for the model.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return mixed
     */
    public function resolve(string $relation, Model $model);
}
