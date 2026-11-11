<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use SineMacula\ApiToolkit\Repositories\Concerns\ResolvesResource;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\Repositories\Repository;

/**
 * The base API repository.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends \SineMacula\Repositories\Repository<TModel>
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ApiRepository extends Repository
{
    use ResolvesResource;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter */
    private AttributeSetter $attributeSetter;

    /**
     * Set a custom resource class to be used.
     *
     * Mutates this handle, so call it before withApiCriteria() copies it.
     *
     * @param  string|null  $resourceClass
     * @return $this
     */
    public function usingResource(?string $resourceClass): static
    {
        $this->customResourceClass = $resourceClass;

        foreach ($this->getCriteria() as $criteria) {
            if (!($criteria instanceof ApiCriteria)) {
                continue;
            }

            $criteria->usingResource($resourceClass);
        }

        return $this;
    }

    /**
     * Get the resource class for this repository's model.
     *
     * @return string|null
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function getResourceClass(): ?string
    {
        return $this->resolveResource($this->app->make($this->model()));
    }

    /**
     * Compose the API criteria into the next query.
     *
     * Returns a copy and leaves this handle untouched, so keep the result.
     *
     * @return static
     *
     * @phpstan-pure
     */
    public function withApiCriteria(): static
    {
        $criteria = $this->app->make(ApiCriteria::class); // @phpstan-ignore possiblyImpure.methodCall

        if ($this->customResourceClass) {
            $criteria->usingResource($this->customResourceClass); // @phpstan-ignore possiblyImpure.methodCall
        }

        return $this->withCriteria($criteria); // @phpstan-ignore argument.type
    }

    /**
     * Return a paginated collection.
     *
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    public function paginate(): mixed
    {
        $query  = $this->prepareQueryBuilder();
        $method = $this->resolvePaginationMethod();
        $limit  = ApiQuery::getResolvedLimit();

        if ($method === 'cursorPaginate') {
            $results = $query->cursorPaginate($limit, '*', 'cursor', ApiQuery::getCursor());
        } else {
            $results = $query->paginate($limit, '*', 'page', ApiQuery::getPage());
        }

        $results->appends(Request::query());

        return $this->resetAndReturn($results);
    }

    /**
     * Persist the given attributes to the model.
     *
     * Handles create and update; many-to-many relations sync after the save.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>|\Illuminate\Support\Collection<string, mixed>  $attributes
     * @return bool
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     * @throws \ValueError
     */
    public function persist(Model $model, array|Collection $attributes): bool
    {
        $attributes = $attributes instanceof Collection ? $attributes->all() : $attributes;

        return $this->attributeSetter->persist($model, $attributes, $this->model());
    }

    /**
     * Scope the model by the given id.
     *
     * @param  int|string|null  $id
     * @param  string  $column
     * @return static
     *
     * @phpstan-pure
     */
    public function scopeById(int|string|null $id, string $column = 'id'): static
    {
        return $this->scopeByIds([$id], $column);
    }

    /**
     * Scope the model by the given ids.
     *
     * @param  array<int, int|string|null>  $ids
     * @param  string  $column
     * @return static
     *
     * @phpstan-pure
     */
    public function scopeByIds(array $ids, string $column = 'id'): static
    {
        return $this->addScope(function (Builder $query) use ($column, $ids): void {
            $query->getQuery()->whereIn($column, array_unique($ids));
        });
    }

    /**
     * Boot the repository instance.
     *
     * @return void
     *
     * @throws \SineMacula\Repositories\Exceptions\RepositoryException
     */
    #[\Override]
    protected function boot(): void
    {
        $schemaIntrospector = $this->app->make(SchemaIntrospectionProvider::class);

        $this->attributeSetter = new AttributeSetter($schemaIntrospector, $this->app->make(MetadataCacheWriter::class));
        $this->attributeSetter->resolveAttributeCasts($this->getModel(), $this->model());
    }

    /**
     * Get the metadata cache writer used by the ResolvesResource concern.
     *
     * @return \SineMacula\ApiToolkit\Cache\MetadataCacheWriter
     */
    #[\Override]
    protected function metadataCacheWriter(): MetadataCacheWriter
    {
        return $this->app->make(MetadataCacheWriter::class);
    }

    /**
     * Resolve which pagination method to use.
     *
     * @return string
     */
    private function resolvePaginationMethod(): string
    {
        if (ApiQuery::isCursorPaginated()) {
            return 'cursorPaginate';
        }

        return 'paginate';
    }
}
