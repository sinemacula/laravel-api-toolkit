<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Introspection;

/**
 * What a connection reports about the indexes a proof may reason from.
 *
 * The facts stay apart because each reader is defeated by a different subset.
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

    /** @var array<int, string> The names whose leading key is collated apart from its column, lowered */
    private array $recollated;

    /**
     * Create a new index eligibility report.
     *
     * @param  array<int, string>  $disregarded
     * @param  array<int, string>  $restricted
     * @param  array<int, string>  $expressed
     * @param  array<int, string>  $unordered
     * @param  array<int, string>  $recollated
     * @return void
     */
    public function __construct(array $disregarded = [], array $restricted = [], array $expressed = [], array $unordered = [], array $recollated = [])
    {
        $this->disregarded = array_map(strtolower(...), $disregarded);
        $this->restricted  = array_map(strtolower(...), $restricted);
        $this->expressed   = array_map(strtolower(...), $expressed);
        $this->unordered   = array_map(strtolower(...), $unordered);
        $this->recollated  = array_map(strtolower(...), $recollated);
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
     * Determine whether the index leads with an expression, not a column.
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
     * A recollated key is ordered by its own collation, so it counts here too.
     *
     * @param  string  $name
     * @return bool
     */
    public function lacksColumnOrder(string $name): bool
    {
        return $this->names($name, $this->unordered) || $this->collatesApart($name);
    }

    /**
     * Determine whether the leading key is collated apart from its column.
     *
     * @param  string  $name
     * @return bool
     */
    public function collatesApart(string $name): bool
    {
        return $this->names($name, $this->recollated);
    }

    /**
     * Determine whether a proof over the reported columns may use the index.
     *
     * @param  string  $name
     * @return bool
     */
    public function describes(string $name): bool
    {
        return !$this->disregards($name)
            && !$this->restricts($name)
            && !$this->keysAnExpression($name)
            && !$this->collatesApart($name);
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
