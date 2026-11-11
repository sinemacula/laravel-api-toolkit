<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Thrown when the registered search driver cannot serve a declared strategy.
 *
 * A deployment defect that fails loudly instead of scanning the whole table on
 * every request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UnservableSearchException extends \RuntimeException
{
    /**
     * Create the exception for a strategy the driver does not implement.
     *
     * @param  string  $engine
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return self
     */
    public static function unsupportedStrategy(string $engine, SearchStrategy $strategy): self
    {
        return new self(sprintf(
            'The search driver serving the "%s" engine does not implement the "%s" match strategy this resource declares.',
            $engine,
            $strategy->value,
        ));
    }

    /**
     * Create the exception for strategies the driver cannot resolve from an
     * index once they are declared together.
     *
     * @param  string  $engine
     * @param  string  $defect
     * @return self
     */
    public static function unservableCombination(string $engine, string $defect): self
    {
        return new self(sprintf(
            'The search driver serving the "%s" engine cannot serve the match strategies this resource declares together, because %s.',
            $engine,
            $defect,
        ));
    }

    /**
     * Create the exception for a strategy the connection carries no index for.
     *
     * @param  string  $connection
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @param  array<int, string>  $defects
     * @return self
     */
    public static function missingIndex(string $connection, SearchStrategy $strategy, array $defects): self
    {
        return new self(sprintf(
            'The "%s" connection carries no index serving the "%s" match strategy this resource declares, so the search would scan the table: %s.',
            $connection,
            $strategy->value,
            implode('; ', $defects),
        ));
    }

    /**
     * Create the exception for a connection that reports no name of its own.
     *
     * An unnamed connection cannot be waived, so no waiver is suggested.
     *
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return self
     */
    public static function unnamedConnection(SearchStrategy $strategy): self
    {
        return new self(sprintf(
            'The connection serving this resource reports no name, so the search driver cannot prove an index serves the "%s" match '
            . 'strategy and the proof cannot be waived either, since the waiver is a list of connection names.',
            $strategy->value,
        ));
    }

    /**
     * Create the exception for a strategy no index is known to serve.
     *
     * Names the connection, not the engine, since the waiver is keyed by
     * connection name.
     *
     * @param  string  $connection
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return self
     */
    public static function unprovenIndexBacking(string $connection, SearchStrategy $strategy): self
    {
        return new self(sprintf(
            'The search driver serving the "%s" connection cannot prove an index serves the "%s" match strategy, so the search would scan the table. '
            . 'List that connection under api-toolkit.search.unverified_connections to serve it regardless.',
            $connection,
            $strategy->value,
        ));
    }
}
