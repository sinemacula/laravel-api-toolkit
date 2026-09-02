<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\SearchStrategy;

/**
 * Thrown when the registered search driver cannot serve a declared strategy.
 *
 * Either the driver does not implement the match shape the resource declared,
 * or it cannot prove an index on this connection serves that shape and the
 * connection is not one where the proof has been waived. Both are deployment
 * defects rather than client mistakes, and both fail loudly: the alternative is
 * a predicate that reads the whole table on every request, which is the outcome
 * declaring the shape exists to prevent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class UnservableSearchException extends \RuntimeException
{
    /**
     * Create the exception for a strategy the driver does not implement.
     *
     * @param  string  $connection
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return self
     */
    public static function unsupportedStrategy(string $connection, SearchStrategy $strategy): self
    {
        return new self(sprintf(
            'The search driver registered for the "%s" connection does not implement the "%s" match strategy this resource declares.',
            $connection,
            $strategy->value,
        ));
    }

    /**
     * Create the exception for a strategy no index is known to serve.
     *
     * @param  string  $connection
     * @param  \SineMacula\ApiToolkit\Enums\SearchStrategy  $strategy
     * @return self
     */
    public static function unprovenIndexBacking(string $connection, SearchStrategy $strategy): self
    {
        return new self(sprintf(
            'The search driver registered for the "%s" connection cannot prove an index serves the "%s" match strategy, so the search would scan the table. '
            . 'List the connection under api-toolkit.search.unverified_connections to serve it regardless.',
            $connection,
            $strategy->value,
        ));
    }
}
