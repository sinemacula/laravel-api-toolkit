<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;
use SineMacula\ApiToolkit\Enums\TrashedState;
use SineMacula\ApiToolkit\Search\SearchTerm;

/**
 * Validates and parses the API parameters in a request's query string.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @inheritable
 */
class ApiQueryParser
{
    /** @var array<string, mixed> */
    protected array $parameters = [];

    /** @var bool Whether the parsed request reads by cursor rather than by page */
    protected bool $cursorPaginated = false;

    /** @var \SineMacula\ApiToolkit\Concerns\QueryParameterValidator */
    private readonly QueryParameterValidator $validator;

    /** @var \SineMacula\ApiToolkit\Concerns\QueryParameterExtractor */
    private readonly QueryParameterExtractor $extractor;

    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct()
    {
        $this->validator = new QueryParameterValidator;
        $this->extractor = new QueryParameterExtractor;
    }

    /**
     * Returns a list of fields set with the URL modifiers.
     *  - e.g. ?fields['user']=first_name,last_name.
     *
     * @param  string|null  $resource
     * @return array<int, string>|null
     */
    public function getFields(?string $resource = null): ?array
    {
        return $this->trimStringList($this->getParameters('fields', $resource));
    }

    /**
     * Returns a list of relation counts set with the URL modifiers.
     * - e.g. ?counts['user']=memberships.
     *
     * @param  string|null  $resource
     * @return array<int, string>|null
     */
    public function getCounts(?string $resource = null): ?array
    {
        return $this->trimStringList($this->getParameters('counts', $resource));
    }

    /**
     * Returns a list of relation sums set with the URL modifiers.
     * - e.g. ?sums['account'][transaction]=amount.
     *
     * @param  string|null  $resource
     * @return array<string, mixed>|null
     */
    public function getSums(?string $resource = null): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->getParameters('sums', $resource);
    }

    /**
     * Returns a list of relation averages set with the URL modifiers.
     * - e.g. ?averages['account'][transaction]=amount.
     *
     * @param  string|null  $resource
     * @return array<string, mixed>|null
     */
    public function getAverages(?string $resource = null): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->getParameters('averages', $resource);
    }

    /**
     * Returns a list of filters set with the URL modifiers.
     *
     * @return array<string, mixed>|null
     */
    public function getFilters(): ?array
    {
        /** @var array<string, mixed>|null $filters */
        $filters = $this->getParameters('filters');

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
        $search = $this->getParameters('search');

        return $search instanceof SearchTerm ? $search : null;
    }

    /**
     * Returns the desired order set with the URL modifiers.
     *  - e.g. ?order=first_name,last_name:desc
     *  - e.g. ?order=random.
     *
     * @return array<string, string>
     */
    public function getOrder(): array
    {
        /** @var array<string, string>|null $order */
        $order = $this->getParameters('order');

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
        $limit = $this->getParameters('limit');

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
        $page = $this->getParameters('page');

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
        $limit = $this->getLimit() ?? Config::get('api-toolkit.parser.defaults.limit');

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 1;
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
        return $this->cursorPaginated;
    }

    /**
     * Returns the current page cursor.
     *  - e.g. ?cursor=eyJpZCI6MTAwfQ==.
     *
     * @return string|null
     */
    public function getCursor(): ?string
    {
        $cursor = $this->getParameters('cursor');

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
        $trashed = $this->getParameters('trashed');

        return TrashedState::fromParameter(is_scalar($trashed) ? (string) $trashed : null);
    }

    /**
     * Reset the parser by clearing all parsed parameters.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->parameters = [];
    }

    /**
     * Parse the given query string to obtain resource and value information.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \SineMacula\ApiToolkit\Exceptions\QueryTooExpensiveException
     */
    public function parse(Request $request): void
    {
        $this->validator->validate($request->all());

        $this->parameters = $this->extractor->extract($request);

        // Read from the parsed request, which may not be the current one
        $this->cursorPaginated = $request->query('pagination') === 'cursor'
            || $request->has('cursor');
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

    /**
     * Extract the specified parameter.
     *
     * @param  string  $option
     * @param  string|null  $resource
     * @return mixed
     */
    private function getParameters(string $option, ?string $resource = null): mixed
    {
        if ($resource) {
            return $this->parameters[$option][$resource] ?? null;
        }

        return $this->parameters[$option] ?? null;
    }
}
