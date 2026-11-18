<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Concerns\QuerySelectionValidator;
use SineMacula\ApiToolkit\Config\QuerySettings;
use SineMacula\ApiToolkit\Enums\TrashedState;
use SineMacula\ApiToolkit\Http\Resources\Concerns\EagerLoadPlanner;
use SineMacula\ApiToolkit\Query\ApiQuerySnapshot;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Validates and parses the API parameters in a request's query string.
 *
 * @phpstan-type ParsedQuery array{parameters: array<string, mixed>, cursorPaginated: bool, snapshot: \SineMacula\ApiToolkit\Query\ApiQuerySnapshot, token: object}
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @inheritable
 */
class ApiQueryParser
{
    /** @var ParsedQuery|null The parsed query, replaced as a whole, or null when none is parsed */
    private ?array $state = null;

    /** @var \SineMacula\ApiToolkit\Concerns\QueryParameterValidator */
    private readonly QueryParameterValidator $validator;

    /** @var \SineMacula\ApiToolkit\Concerns\QueryParameterExtractor */
    private readonly QueryParameterExtractor $extractor;

    /** @var \SineMacula\ApiToolkit\Concerns\QuerySelectionValidator */
    private readonly QuerySelectionValidator $selectionValidator;

    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct()
    {
        $this->validator          = new QueryParameterValidator;
        $this->extractor          = new QueryParameterExtractor;
        $this->selectionValidator = new QuerySelectionValidator;
    }

    /**
     * Returns the fields selected for the given resource type.
     *  - e.g. ?fields[users]=first_name,last_name.
     *
     * @param  string  $resource
     * @return array<int, string>|null
     */
    public function getFields(string $resource): ?array
    {
        return $this->trimStringList($this->state['parameters']['fields'][$resource] ?? null);
    }

    /**
     * Returns the relation aggregate keys selected for the given resource type.
     *  - e.g. ?aggregates[users]=posts_count,orders_sum_total.
     *
     * @param  string  $resource
     * @return array<int, string>|null
     */
    public function getAggregates(string $resource): ?array
    {
        return $this->trimStringList($this->state['parameters']['aggregates'][$resource] ?? null);
    }

    /**
     * Returns a list of filters set with the URL modifiers.
     *
     * @return array<string, mixed>|null
     */
    public function getFilters(): ?array
    {
        /** @var array<string, mixed>|null $filters */
        $filters = $this->state['parameters']['filters'] ?? null;

        return $filters ?? [];
    }

    /**
     * Returns the parsed free-text search term set with the URL modifiers.
     *  - e.g. ?search=John Smith.
     *
     * @return \SineMacula\ApiToolkit\Search\SearchTerm|null
     */
    public function getSearch(): ?SearchTerm
    {
        $search = $this->state['parameters']['search'] ?? null;

        return $search instanceof SearchTerm ? $search : null;
    }

    /**
     * Returns the desired order set with the URL modifiers.
     *  - e.g. ?order=first_name,last_name:desc
     *  - e.g. ?order=random.
     *
     * @return array<string, 'asc'|'desc'>
     */
    public function getOrder(): array
    {
        /** @var array<string, 'asc'|'desc'>|null $order */
        $order = $this->state['parameters']['order'] ?? null;

        return $order ?? [];
    }

    /**
     * Returns the desired limit set with the URL modifiers.
     *  - e.g. ?limit=x.
     *
     * @return int|null
     */
    public function getLimit(): ?int
    {
        $limit = $this->state['parameters']['limit'] ?? null;

        $limit = is_numeric($limit) ? (int) $limit : 0;

        return $limit > 0 ? $limit : null;
    }

    /**
     * Returns the current page set with the URL modifiers.
     *  - e.g. ?page=4.
     *
     * @return int|null
     */
    public function getPage(): ?int
    {
        $page = $this->state['parameters']['page'] ?? null;

        $page = is_numeric($page) ? (int) $page : 0;

        return $page > 0 ? $page : 1;
    }

    /**
     * Return the page size the read will use, falling back to the configured
     * default.
     *
     * Shared by the cost guard and the query so the two cannot disagree.
     *
     * @return int
     */
    public function getResolvedLimit(): int
    {
        return $this->getLimit() ?? QuerySettings::defaultLimit();
    }

    /**
     * Determine whether the read is paginated by cursor.
     *
     * A cursor seeks to its position, so a page number alongside one is not a
     * paid offset.
     *
     * @return bool
     */
    public function isCursorPaginated(): bool
    {
        return $this->state['cursorPaginated'] ?? false;
    }

    /**
     * Returns the current page cursor.
     *  - e.g. ?cursor=eyJpZCI6MTAwfQ==.
     *
     * @return string|null
     */
    public function getCursor(): ?string
    {
        $cursor = $this->state['parameters']['cursor'] ?? null;

        return is_scalar($cursor) ? (string) $cursor : '';
    }

    /**
     * Returns the requested soft-delete visibility set with the URL modifiers.
     *  - e.g. ?trashed=with.
     *
     * @return \SineMacula\ApiToolkit\Enums\TrashedState
     */
    public function getTrashed(): TrashedState
    {
        $trashed = $this->state['parameters']['trashed'] ?? null;

        return TrashedState::fromParameter(is_string($trashed) ? $trashed : null);
    }

    /**
     * Determine whether a query has been parsed.
     *
     * @return bool
     */
    public function isParsed(): bool
    {
        return $this->state !== null;
    }

    /**
     * Return the selection the current query carries, for replay through
     * using().
     *
     * @return \SineMacula\ApiToolkit\Query\ApiQuerySnapshot
     *
     * @throws \LogicException
     */
    public function snapshot(): ApiQuerySnapshot
    {
        if ($this->state === null) {
            throw new \LogicException('No API query has been parsed to snapshot.');
        }

        return $this->state['snapshot'];
    }

    /**
     * Run the callback with the given snapshot as the current query, restoring
     * the previous query afterwards.
     *
     * The snapshot is validated as a request is, before the callback runs.
     *
     * @template T
     *
     * @param  \SineMacula\ApiToolkit\Query\ApiQuerySnapshot  $query
     * @param  \Closure(): T  $callback
     * @return T
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function using(ApiQuerySnapshot $query, \Closure $callback): mixed
    {
        $previous = $this->state;

        $this->load($query->parameters, false);

        try {
            return $callback();
        } finally {
            $this->reset();

            $this->state = $previous;
        }
    }

    /**
     * Return the identity of the current query, or null when none is parsed.
     *
     * @return object|null
     *
     * @internal
     */
    public function token(): ?object
    {
        return $this->state['token'] ?? null;
    }

    /**
     * Reset the parser to its unparsed state.
     *
     * Every change of query passes through here: the eager-load plans read
     * child selections from the query, so a plan built under another one is
     * dropped.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->state = null;

        EagerLoadPlanner::clearCache();
    }

    /**
     * Parse the given request's query string to obtain resource and value
     * information.
     *
     * The body is never read. A rejected parse leaves the previous state.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function parse(Request $request): void
    {
        // Read from the parsed request, which may not be the current one
        $this->load($request->query->all(), $request->query('pagination') === 'cursor' || $request->query->has('cursor'));
    }

    /**
     * Validate and extract the given input, then make it the current query.
     *
     * Nothing is assigned until the input is accepted.
     *
     * @param  array<string, mixed>  $input
     * @param  bool  $cursorPaginated
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    private function load(array $input, bool $cursorPaginated): void
    {
        $this->validator->validate($input);

        $parameters = $this->extractor->extract($input);

        $this->selectionValidator->validate($parameters);

        $this->reset();

        $this->state = [
            'parameters'      => $parameters,
            'cursorPaginated' => $cursorPaginated,
            'snapshot'        => new ApiQuerySnapshot(array_intersect_key($input, array_flip(ApiQuerySnapshot::KEYS))),
            'token'           => new \stdClass,
        ];
    }

    /**
     * Trim a flat list of string values, skipping any non-string entry.
     *
     * @param  mixed  $values
     * @return array<int, string>|null
     */
    private function trimStringList(mixed $values): ?array
    {
        if (!is_array($values)) {
            return null;
        }

        return array_values(array_map('trim', array_filter($values, 'is_string')));
    }
}
