<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Orders search matches by the best way each row matches the term.
 *
 * A row ranks by its whole value, then its start, then the start of a word,
 * then anywhere, each tested only where a column's strategy can produce it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final readonly class RelevanceOrder
{
    /** @var array<int, array{string, array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>}> Each tier's pattern around the escaped term and the strategies reaching it, best first */
    private const array TIERS = [
        ['%s', [SearchStrategy::EXACT, SearchStrategy::PREFIX, SearchStrategy::SUBSTRING]],
        ['%s%%', [SearchStrategy::PREFIX, SearchStrategy::SUBSTRING]],
        ['%% %s%%', [SearchStrategy::SUBSTRING]],
    ];

    /**
     * Order the query by the tier of each row's best match.
     *
     * Nothing is added where every match can only fall in the first tier.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  \SineMacula\ApiToolkit\Search\SearchPlan  $plan
     * @param  \SineMacula\ApiToolkit\Search\SearchTerm  $term
     * @param  'ilike'|'like'  $operator
     * @return void
     */
    public function apply(Builder $query, SearchPlan $plan, SearchTerm $term, string $operator): void
    {
        $cases    = [];
        $bindings = [];

        foreach (self::TIERS as $tier => [$pattern, $strategies]) {

            $tests = [];

            foreach ($this->columns($plan, $strategies) as [$column, $strategy]) {
                $tests[]    = $this->test($query, $column, $strategy, $operator);
                $bindings[] = $strategy === SearchStrategy::EXACT ? $term->value() : sprintf($pattern, $term->escaped());
            }

            if ($tests === []) {
                continue;
            }

            $cases[] = sprintf('when %s then %d', implode(' or ', $tests), $tier);
        }

        if (count($cases) < 2) {
            return;
        }

        // Only grammar-wrapped columns are interpolated; every term is bound
        $ranking = new Expression(sprintf('case %s else %d end', implode(' ', $cases), count(self::TIERS))); // @phpstan-ignore argument.type

        $query->getQuery()->orderBy($ranking)->addBinding($bindings, 'order');
    }

    /**
     * Return the plan's columns declared with the given strategies, in order.
     *
     * @param  \SineMacula\ApiToolkit\Search\SearchPlan  $plan
     * @param  array<int, \SineMacula\ApiToolkit\Enums\SearchStrategy>  $strategies
     * @return array<int, array{string, \SineMacula\ApiToolkit\Enums\SearchStrategy}>
     */
    private function columns(SearchPlan $plan, array $strategies): array
    {
        $columns = [];

        foreach ($strategies as $strategy) {
            foreach ($plan->columnsFor($strategy) as $column) {
                $columns[] = [$column, $strategy];
            }
        }

        return $columns;
    }

    /**
     * Render the test of one column, with one placeholder for its pattern.
     *
     * An equality column is compared as its driver matches it, with "=".
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  string  $column
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  'ilike'|'like'  $operator
     * @return string
     */
    private function test(Builder $query, string $column, SearchStrategy $strategy, string $operator): string
    {
        $qualified = $query->qualifyColumn($column);
        $grammar   = $query->getQuery()->getGrammar();

        return $strategy === SearchStrategy::EXACT
            ? $grammar->wrap($qualified) . ' = ?'
            : ColumnCondition::escapedPattern($qualified, $operator)->getValue($grammar);
    }
}
