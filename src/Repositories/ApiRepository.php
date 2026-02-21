<?php

namespace SineMacula\ApiToolkit\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Traits\ManagesApiRepositoryAttributes;
use SineMacula\ApiToolkit\Repositories\Traits\ResolvesResource;
use SineMacula\Repositories\Repository;

/**
 * The base API repository.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 *
 * @extends \SineMacula\Repositories\Repository<\Illuminate\Database\Eloquent\Model>
 */
abstract class ApiRepository extends Repository
{
    use ManagesApiRepositoryAttributes;
    use ResolvesResource;

    /** @var array<string, string> */
    protected array $casts = [];

    /**
     * Set a custom resource class to be used.
     *
     * @param  string|null  $resource_class
     * @return $this
     */
    public function usingResource(?string $resource_class): static
    {
        $this->customResourceClass = $resource_class;

        foreach ($this->getCriteria() as $criteria) {
            if ($criteria instanceof ApiCriteria) {
                $criteria->usingResource($resource_class);
            }
        }

        return $this;
    }

    /**
     * Get the resource class for this repository's model.
     *
     * @return string|null
     */
    public function getResourceClass(): ?string
    {
        return $this->resolveResource($this->app->make($this->model()));
    }

    /**
     * Apply the API criteria to the next request.
     *
     * @return static
     */
    public function withApiCriteria(): static
    {
        $criteria = $this->app->make(ApiCriteria::class);

        if ($this->customResourceClass) {
            $criteria->usingResource($this->customResourceClass);
        }

        return $this->withCriteria($criteria);
    }

    /**
     * Return a paginated collection.
     *
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function paginate(): mixed
    {
        $this->applyCriteria();
        $this->applyScopes();

        $query  = $this->resolvePaginationBuilder();
        $method = $this->resolvePaginationMethod();
        $limit  = ApiQuery::getLimit() ?? Config::get('api-toolkit.parser.defaults.limit');

        $results = $method === 'cursorPaginate'
            ? $query->cursorPaginate($limit, ['*'], 'cursor', ApiQuery::getCursor())
            : $query->paginate($limit, ['*'], 'page', ApiQuery::getPage());

        $results->appends(Request::query());

        return $this->resetAndReturn($results);
    }

    /**
     * Set the attributes for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>|\Illuminate\Support\Collection<string, mixed>  $attributes
     * @return bool
     */
    public function setAttributes(Model $model, array|Collection $attributes): bool
    {
        $attributes = $attributes instanceof Collection ? $attributes->all() : $attributes;

        $sync_attributes = [];

        foreach ($attributes as $attribute => $value) {
            $cast = $this->casts[$attribute] ?? $this->resolveCastForAttribute($attribute);

            if ($cast === null) {
                continue;
            }

            $this->casts[$attribute] = $cast;

            if ($cast === 'sync') {
                $sync_attributes[$attribute] = $value;
                continue;
            }

            $this->setAttribute($model, $attribute, $value, $cast);
        }

        $saved = $model->save();

        foreach ($sync_attributes as $attribute => $value) {
            $this->setAttribute($model, $attribute, $value, 'sync');
        }

        $this->storeCastsInCache();

        return $saved;
    }

    /**
     * Scopes the model by the given id.
     *
     * @param  int|string|null  $id
     * @param  string  $column
     * @return static
     */
    public function scopeById(int|string|null $id, string $column = 'id'): static
    {
        return $this->scopeByIds([$id], $column);
    }

    /**
     * Scopes the model by the given ids.
     *
     * @param  array<int, int|string|null>  $ids
     * @param  string  $column
     * @return static
     */
    public function scopeByIds(array $ids, string $column = 'id'): static
    {
        return $this->addScope(function ($query) use ($column, $ids): void {
            if ($query instanceof Builder) {
                $query->getQuery()->whereIn($column, array_unique($ids, SORT_REGULAR));
            }
        });
    }

    /**
     * Boot the repository instance.
     *
     * @return void
     */
    protected function boot(): void
    {
        $this->resolveAttributeCasts();
    }

    /**
     * Resolve which pagination method to use.
     *
     * @return string
     */
    private function resolvePaginationMethod(): string
    {
        return Request::query('pagination') === 'cursor' || Request::has('cursor')
            ? 'cursorPaginate'
            : 'paginate';
    }

    /**
     * Resolve the builder instance used for pagination.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function resolvePaginationBuilder(): Builder
    {
        if ($this->model instanceof Builder) {
            return $this->model;
        }

        return $this->getModel()->newQuery();
    }
}
