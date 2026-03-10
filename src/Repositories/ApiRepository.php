<?php

namespace SineMacula\ApiToolkit\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria;
use SineMacula\ApiToolkit\Repositories\Traits\ResolvesResource;
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

        $method = $this->resolvePaginationMethod();
        $limit  = ApiQuery::getLimit() ?? Config::get('api-toolkit.parser.defaults.limit');

        if ($method === 'cursorPaginate') {
            $results = $this->model->cursorPaginate($limit, '*', 'cursor', ApiQuery::getCursor());
        } else {
            $results = $this->model->paginate($limit, '*', 'page', ApiQuery::getPage());
        }

        $results->appends(Request::query());

        return $this->resetAndReturn($results);
    }

    /**
     * Set the attributes for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array|\Illuminate\Support\Collection  $attributes
     * @return bool
     */
    public function setAttributes(Model $model, array|Collection $attributes): bool
    {
        $attributes = $attributes instanceof Collection ? $attributes->all() : $attributes;

        return $this->attributeSetter->setAttributes($model, $attributes, $this->model());
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
     * @param  array  $ids
     * @param  string  $column
     * @return static
     */
    public function scopeByIds(array $ids, string $column = 'id'): static
    {
        return $this->addScope(function (Builder $query) use ($column, $ids): void {
            $query->whereIn($column, array_unique($ids));
        });
    }

    /**
     * Boot the repository instance.
     *
     * This is a useful method for setting immediate properties when extending
     * the base repository class.
     *
     * @return void
     */
    #[\Override]
    protected function boot(): void
    {
        $schemaIntrospector = $this->app->make(SchemaIntrospectionProvider::class);

        $this->attributeSetter = new AttributeSetter($schemaIntrospector);
        $this->attributeSetter->resolveAttributeCasts($this->model, $this->model());
    }

    /**
     * Resolve which pagination method to use.
     *
     * @return string
     */
    private function resolvePaginationMethod(): string
    {
        if (Request::query('pagination') === 'cursor' || Request::has('cursor')) {
            return 'cursorPaginate';
        }

        return 'paginate';
    }
}
