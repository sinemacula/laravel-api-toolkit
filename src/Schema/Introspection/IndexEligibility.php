<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

/**
 * What a connection reports about the indexes a proof may reason from.
 *
 * The shared catalogue read names every index a table declares without saying
 * whether a proof may rest on one, and four separate facts decide that. An
 * index the engine disregards is one no plan will reach. An index that is
 * restricted holds only the rows its own predicate admits, so it serves only a
 * query carrying that predicate. An index keyed on an expression leads with
 * something the catalogue cannot report as a column, and because the column
 * list is aggregated over the parts that do name columns, the part naming none
 * is dropped and whatever follows it reads as the column the index leads with.
 *
 * The fourth is read only by a proof that needs the leading key to deliver the
 * column's own order. A key truncated to a prefix of its column, or ordered by
 * an operator family or a collation other than the column's, cannot hand rows
 * back in the column's order. A truncated or pattern-class key still finds rows
 * by that column, while one collated apart from it does not.
 *
 * The facts are kept apart rather than flattened into one refusal because they
 * do not disqualify an index for the same readers. A proof reading the reported
 * columns is defeated by the first three; a developer naming an index outright
 * is asserting what the column list was never going to show, and is defeated
 * only by the engine refusing the index altogether.
 *
 * Every list is held lowered, and a name is lowered before it is compared,
 * because the catalogue reports names folded while a declaration may name one
 * in whatever case it was created with.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class IndexEligibility
{
    /** @var array<int, string> The names the engine will not plan against, lowered */
    private array $disregarded;

    /** @var array<int, string> The names holding only part of the table, lowered */
    private array $restricted;

    /** @var array<int, string> The names whose leading key is an expression, lowered */
    private array $expressed;

    /** @var array<int, string> The names whose leading key does not hold its column's own order, lowered */
    private array $unordered;

    /**
     * Create a new index eligibility report.
     *
     * A connection that cannot distinguish these facts reports none of them,
     * which leaves every reader exactly as strict as it was without the report.
     *
     * @param  array<int, string>  $disregarded
     * @param  array<int, string>  $restricted
     * @param  array<int, string>  $expressed
     * @param  array<int, string>  $unordered
     * @return void
     */
    public function __construct(array $disregarded = [], array $restricted = [], array $expressed = [], array $unordered = [])
    {
        $this->disregarded = array_map(strtolower(...), $disregarded);
        $this->restricted  = array_map(strtolower(...), $restricted);
        $this->expressed   = array_map(strtolower(...), $expressed);
        $this->unordered   = array_map(strtolower(...), $unordered);
    }

    /**
     * Determine whether the engine will refuse to plan against the index.
     *
     * @param  string  $name
     * @return bool
     */
    public function disregards(string $name): bool
    {
        return $this->names($name, $this->disregarded);
    }

    /**
     * Determine whether the index holds only the rows its own predicate admits.
     *
     * @param  string  $name
     * @return bool
     */
    public function restricts(string $name): bool
    {
        return $this->names($name, $this->restricted);
    }

    /**
     * Determine whether the index leads with an expression rather than one of
     * the columns the catalogue reports for it.
     *
     * @param  string  $name
     * @return bool
     */
    public function keysAnExpression(string $name): bool
    {
        return $this->names($name, $this->expressed);
    }

    /**
     * Determine whether the leading key cannot deliver its column's own order.
     *
     * @param  string  $name
     * @return bool
     */
    public function lacksColumnOrder(string $name): bool
    {
        return $this->names($name, $this->unordered);
    }

    /**
     * Determine whether a proof reading the reported columns may rest on the
     * index, which needs the first three facts to be absent.
     *
     * @param  string  $name
     * @return bool
     */
    public function describes(string $name): bool
    {
        return !$this->disregards($name)
            && !$this->restricts($name)
            && !$this->keysAnExpression($name);
    }

    /**
     * Determine whether the given list names the index.
     *
     * @param  string  $name
     * @param  array<int, string>  $names
     * @return bool
     */
    private function names(string $name, array $names): bool
    {
        return in_array(strtolower($name), $names, true);
    }
}
