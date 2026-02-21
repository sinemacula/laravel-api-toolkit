<?php

namespace SineMacula\ApiToolkit;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Concerns\ParsesApiQueryParameters;

/**
 * API query parser.
 *
 * Extract API parameters supplied within the query string of a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2025 Sine Macula Limited.
 */
class ApiQueryParser
{
    use ParsesApiQueryParameters;

    /** @var array<string, mixed> */
    protected array $parameters = [];

    /**
     * Returns a list of fields set with the URL modifiers.
     *  - e.g. ?fields['user']=first_name,last_name.
     *
     * @param  string|null  $resource
     * @return array<int, string>|array<string, array<int, string>>|null
     */
    public function getFields(?string $resource = null): ?array
    {
        return $this->normalizeResourceSelection($this->getParameters('fields', $resource));
    }

    /**
     * Returns a list of relation counts set with the URL modifiers.
     * - e.g. ?counts['user']=memberships.
     *
     * @param  string|null  $resource
     * @return array<int, string>|array<string, array<int, string>>|null
     */
    public function getCounts(?string $resource = null): ?array
    {
        return $this->normalizeResourceSelection($this->getParameters('counts', $resource));
    }

    /**
     * Returns a list of relation sums set with the URL modifiers.
     * - e.g. ?sums['account'][transaction]=amount.
     *
     * @param  string|null  $resource
     * @return array<string, array<int, string>>|array<string, array<string, array<int, string>>>|null
     */
    public function getSums(?string $resource = null): ?array
    {
        return $this->getAggregationParameter('sums', $resource);
    }

    /**
     * Returns a list of relation averages set with the URL modifiers.
     * - e.g. ?averages['account'][transaction]=amount.
     *
     * @param  string|null  $resource
     * @return array<string, array<int, string>>|array<string, array<string, array<int, string>>>|null
     */
    public function getAverages(?string $resource = null): ?array
    {
        return $this->getAggregationParameter('averages', $resource);
    }

    /**
     * Returns a list of filters set with the URL modifiers.
     *
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        $filters = $this->getParameters('filters');

        return is_array($filters) ? $filters : [];
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
        $order = $this->getParameters('order');

        if (!is_array($order)) {
            return [];
        }

        $normalized = [];

        foreach ($order as $column => $direction) {
            if (is_string($column) && is_string($direction)) {
                $normalized[$column] = $direction;
            }
        }

        return $normalized;
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

        if (!is_numeric($limit)) {
            return null;
        }

        $limit = (int) $limit;

        return $limit > 0 ? $limit : null;
    }

    /**
     * Returns the current page set with the URL modifiers.
     *  - e.g. ?page=4.
     *
     * @return int
     */
    public function getPage(): int
    {
        $page = $this->getParameters('page');

        if (!is_numeric($page)) {
            return 1;
        }

        $page = (int) $page;

        return $page > 0 ? $page : 1;
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

        if (!is_string($cursor) || trim($cursor) === '') {
            return null;
        }

        return $cursor;
    }

    /**
     * Parse the given query string to obtain resource and value information.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    public function parse(Request $request): void
    {
        $this->validate($request->all());

        $this->parameters = $this->extractParameters($request);
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
