<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Concerns\QueryParameterExtractor;
use SineMacula\ApiToolkit\Concerns\QueryParameterValidator;

/**
 * API query parser.
 *
 * Thin orchestrator that validates and extracts API parameters supplied
 * within the query string of a request, delegating to single-responsibility
 * concern classes and exposing typed access to the parsed parameters.
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
        /** @var array<int, string>|null $fields */
        $fields = $this->getParameters('fields', $resource);

        return is_array($fields) ? array_map('trim', $fields) : $fields;
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
        /** @var array<int, string>|null $counts */
        $counts = $this->getParameters('counts', $resource);

        return is_array($counts) ? array_map('trim', $counts) : $counts;
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

        if ($limit <= 0) {
            return null;
        }

        $max = Config::get('api-toolkit.parser.max_limit');
        $max = is_numeric($max) ? (int) $max : 0;

        return $max > 0 ? min($limit, $max) : $limit;
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
     */
    public function parse(Request $request): void
    {
        $this->validator->validate($request->all());

        $this->parameters = $this->extractor->extract($request);
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
