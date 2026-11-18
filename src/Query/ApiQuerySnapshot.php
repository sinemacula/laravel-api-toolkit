<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Query;

/**
 * The query parameters that select and shape a read, as the client sent them,
 * for replay outside the request that carried them.
 *
 * The page is never carried, so a replayed read is not cut to one page. The
 * values are strings and arrays of strings, so a snapshot survives queue
 * serialisation and its parameters store as JSON.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ApiQuerySnapshot
{
    /** @var array<int, string> The parameters a snapshot may carry */
    public const array KEYS = ['fields', 'aggregates', 'filters', 'search', 'order', 'trashed'];

    /**
     * Constructor.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(

        /** The carried parameters, keyed by name */
        public array $parameters,
    ) {
        $unknown = array_diff(array_keys($parameters), self::KEYS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('An API query snapshot cannot carry "%s".', implode('", "', $unknown)));
        }
    }

    /**
     * Return a copy without the given parameters.
     *
     * @param  string  ...$keys
     * @return self
     */
    public function without(string ...$keys): self
    {
        return new self(array_diff_key($this->parameters, array_flip($keys)));
    }
}
