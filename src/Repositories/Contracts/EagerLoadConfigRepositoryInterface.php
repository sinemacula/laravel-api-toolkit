<?php

namespace SineMacula\ApiToolkit\Repositories\Contracts;

interface EagerLoadConfigRepositoryInterface
{
    /**
     * Get the eager load relations for a given resource class or model class.
     *
     * @param string $resourceClassOrModelClass
     * @return array
     */
    public function getRelationsFor(string $resourceClassOrModelClass): array;
}
