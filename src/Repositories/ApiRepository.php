<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Exceptions\FailureReporter;
use SineMacula\ApiToolkit\Exceptions\PreconditionFailedException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Concurrency\IfMatch;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use SineMacula\ApiToolkit\Repositories\Concerns\ColumnLiteral;
use SineMacula\ApiToolkit\Repositories\Concerns\OffsetPaginator;
use SineMacula\ApiToolkit\Repositories\Concerns\PageOrder;
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

    /** The message for a locked read composed with request criteria */
    private const string CRITERIA_MESSAGE = '%s::findForUpdate() cannot apply request criteria; call it on a handle without withApiCriteria() or withApiFilters().';

    /** The message for a locked read outside a transaction on its connection */
    private const string UNLOCKED_MESSAGE = '%s::findForUpdate() must run inside a transaction on the [%s] connection, or its lock is released at once.';

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter */
    private AttributeSetter $attributeSetter;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\PageOrder */
    private PageOrder $pageOrder;

    /** @var \SineMacula\ApiToolkit\Repositories\Concerns\OffsetPaginator */
    private OffsetPaginator $offsetPaginator;

    /**
     * Set a custom resource class to be used.
     *
     * Mutates this handle, so call it before withApiCriteria() or
     * withApiFilters() copies it.
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
        return $this->withCriteria($this->makeApiCriteria(false)); // @phpstan-ignore argument.type
    }

    /**
     * Compose only the request's filters, search and trashed into the next
     * query, for work on the whole filtered set.
     *
     * The order, limit, fields and aggregates are neither applied nor checked.
     * Returns a copy, as withApiCriteria() does, and the last of the two called
     * on a handle wins.
     *
     * @return static
     *
     * @phpstan-pure
     */
    public function withApiFilters(): static
    {
        return $this->withCriteria($this->makeApiCriteria(true)); // @phpstan-ignore argument.type
    }

    /**
     * Return a paginated collection.
     *
     * The order is completed with a unique key first, so no page boundary falls
     * between tied rows. A failure resets the composition, as a forwarded call
     * does.
     *
     * @return mixed
     *
     * @throws \Throwable
     */
    public function paginate(): mixed
    {
        try {

            $cursor = ApiQuery::isCursorPaginated();
            $limit  = ApiQuery::getResolvedLimit();
            $page   = ApiQuery::getPage();

            // Refused before composing, which can read the schema
            if (!$cursor) {
                $this->offsetPaginator->guard($page, $limit);
            }

            /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
            $query = $this->prepareQueryBuilder();
            $query = $this->pageOrder->complete($query);

            if ($cursor) {
                // A null cursor would make the paginator re-read the request
                $results = $query->cursorPaginate($limit, '*', 'cursor', $this->pageOrder->cursor($query, ApiQuery::getCursor() ?? '') ?? '');
            } else {
                $results = $this->offsetPaginator->paginate($query, $page, $limit);
            }

            $results->appends(Request::query());

            return $this->resetAndReturn($results);
        } catch (\Throwable $exception) {

            $this->resetAfterFailure();

            throw $exception;
        }
    }

    /**
     * Persist the given attributes to the model.
     *
     * Handles create and update; many-to-many relations sync after the save, in
     * the same transaction on the model's connection. A cacheable repository
     * then invalidates its table, even when the write fails.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array<string, mixed>|\Illuminate\Support\Collection<string, mixed>  $attributes
     * @return bool
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \ValueError
     */
    public function persist(Model $model, array|Collection $attributes): bool
    {
        $attributes = $attributes instanceof Collection ? $attributes->all() : $attributes;

        try {
            return $this->attributeSetter->persist($model, $attributes);
        } finally {
            $this->invalidateCache();
        }
    }

    /**
     * Find the model by its key under a row lock, refusing it when it fails the
     * given If-Match precondition.
     *
     * Reads the stored row afresh, so call it inside the transaction that
     * writes the model. Request criteria never select a row for writing.
     *
     * @param  int|string  $id
     * @param  \SineMacula\ApiToolkit\Http\Concurrency\IfMatch|null  $ifMatch
     * @return \Illuminate\Database\Eloquent\Model
     *
     * @throws \LogicException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \SineMacula\ApiToolkit\Exceptions\PreconditionFailedException
     * @throws \Throwable
     */
    public function findForUpdate(int|string $id, ?IfMatch $ifMatch = null): Model
    {
        if ($this->getCriteria()->contains(static fn (mixed $criteria): bool => $criteria instanceof ApiCriteria)) {
            throw new \LogicException(sprintf(self::CRITERIA_MESSAGE, static::class));
        }

        /** @var \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
        $query      = $this->query();
        $connection = $query->getModel()->getConnection();

        if ($connection->transactionLevel() === 0) {
            throw new \LogicException(sprintf(self::UNLOCKED_MESSAGE, static::class, $connection->getName()));
        }

        $query->getQuery()->lockForUpdate();

        $model = $query->findOrFail($id);

        if ($ifMatch !== null && !$ifMatch->matches($model)) {
            throw new PreconditionFailedException;
        }

        return $model;
    }

    /**
     * Scope the model by the given id, matched against its key by default.
     *
     * @param  int|string|null  $id
     * @param  string|null  $column
     * @return static
     *
     * @phpstan-pure
     */
    public function scopeById(int|string|null $id, ?string $column = null): static
    {
        return $this->scopeByIds([$id], $column);
    }

    /**
     * Scope the model by the given ids, matched against its key by default.
     *
     * @param  array<int, int|string|null>  $ids
     * @param  string|null  $column
     * @return static
     *
     * @phpstan-pure
     */
    public function scopeByIds(array $ids, ?string $column = null): static
    {
        return $this->addScope(function (Builder $query) use ($column, $ids): void {
            $query->getQuery()->whereIn($query->qualifyColumn($column ?? $query->getModel()->getKeyName()), array_unique($ids));
        });
    }

    /**
     * Boot the repository instance.
     *
     * @return void
     */
    #[\Override]
    protected function boot(): void
    {
        $introspector = $this->app->make(SchemaIntrospectionProvider::class);

        $this->pageOrder       = new PageOrder(new ColumnLiteral($introspector));
        $this->offsetPaginator = new OffsetPaginator;
        $this->attributeSetter = new AttributeSetter($introspector);
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
     * Make the API criteria for this handle's resource.
     *
     * @param  bool  $filtersOnly
     * @return \SineMacula\ApiToolkit\Repositories\Criteria\ApiCriteria
     *
     * @phpstan-pure
     */
    private function makeApiCriteria(bool $filtersOnly): ApiCriteria
    {
        $criteria = $this->app->make(ApiCriteria::class, ['filtersOnly' => $filtersOnly]); // @phpstan-ignore possiblyImpure.methodCall

        if ($this->customResourceClass) {
            $criteria->usingResource($this->customResourceClass); // @phpstan-ignore possiblyImpure.methodCall
        }

        return $criteria;
    }

    /**
     * Invalidate a cacheable repository's table, reporting rather than throwing
     * a cache failure, since the write has already happened.
     *
     * @return void
     */
    private function invalidateCache(): void
    {
        if (!method_exists($this, 'flushCache')) {
            return;
        }

        try {
            $this->flushCache();
        } catch (\Throwable $exception) {
            FailureReporter::report($exception, 'Repository cache flush after persist failed.');
        }
    }
}
