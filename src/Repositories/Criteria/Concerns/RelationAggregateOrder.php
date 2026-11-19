<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Repositories\Criteria\SortTarget;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Orders a query by a relation aggregate, as the aggregate renders.
 *
 * Laravel builds the correlated subquery on a probe query, so the order runs
 * the constraints, global scopes and trashed scope the rendered aggregate runs;
 * the subquery then moves from the probe's select list to the order.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RelationAggregateOrder
{
    /** @var string The alias the probe selects the subquery under */
    private const string ALIAS = 'api_toolkit_sort';

    /**
     * Create a new relation aggregate order.
     *
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\Concerns\RelationTrashedGate  $trashedGate
     */
    public function __construct(

        /** Widens the related rows to the trashed state the request asks for */
        private RelationTrashedGate $trashedGate,
    ) {}

    /**
     * Determine whether the relation renders the single row an order reads.
     *
     * A plain has-one renders whichever matching row the engine returns first,
     * which no subquery reproduces.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>  $relation
     * @return bool
     */
    public static function rendersOneRow(Relation $relation): bool
    {
        if ($relation instanceof BelongsTo) {
            return !$relation instanceof MorphTo;
        }

        return ($relation instanceof HasOne || $relation instanceof MorphOne || $relation instanceof HasOneThrough) && $relation->isOneOfMany();
    }

    /**
     * Order the query by the target's aggregate.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  \SineMacula\ApiToolkit\Repositories\Criteria\SortTarget  $target
     * @param  'asc'|'desc'  $direction
     * @param  string  $resourceClass
     * @return void
     *
     * @throws \LogicException
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidSchemaException
     */
    public function apply(Builder $query, SortTarget $target, string $direction, string $resourceClass): void
    {
        $base  = $query->getQuery();
        $model = $query->getModel();

        if (!$target->isAggregate()) {
            throw new \LogicException(sprintf('The "%s" key orders by a column, not a relation aggregate', $target->column));
        }

        // The subquery correlates by table name; a union takes its own orders
        if ($base->from !== $model->getTable() || !empty($base->unions)) {
            throw new \LogicException(sprintf('A relation aggregate cannot order a query over "%s" that reads another table or a union', $model::class));
        }

        $probe = $model->newModelQuery();

        if ($target->toOne && !self::rendersOneRow($probe->getRelation($target->relation))) {
            $defect = 'A sortable relation must be a BelongsTo, or a HasOne, MorphOne or HasOneThrough declared with latestOfMany() or ofMany()';

            throw new InvalidSchemaException([new SchemaValidationError($resourceClass, $target->relation, $defect)]);
        }

        $constraint = $this->trashedGate->gatedConstraint($target->constraint, ApiQuery::getTrashed());

        $probe->withAggregate([$target->relation . ' as ' . self::ALIAS => $constraint], $target->column, $target->function->value);

        $columns = $probe->getQuery()->getColumns();
        $select  = (string) end($columns);
        $suffix  = ' as ' . $probe->getQuery()->getGrammar()->wrap(self::ALIAS);

        if (!str_ends_with($select, $suffix)) {
            throw new \LogicException(sprintf('The aggregate subquery over "%s" did not end in its alias', $target->relation));
        }

        $base->orderBy(new Expression(substr($select, 0, -strlen($suffix))), $direction); // @phpstan-ignore argument.type
        $base->addBinding($probe->getQuery()->getRawBindings()['select'], 'order');
    }
}
