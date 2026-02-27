<?php

namespace SineMacula\ApiToolkit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Concerns\ParsesQueryParameters;
use SineMacula\ApiToolkit\Concerns\ValidatesQueryParameters;

/**
 * API query parser.
 *
 * Extract API parameters supplied within the query string of a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class ApiQueryParser
{
    use ParsesQueryParameters, ValidatesQueryParameters;

    /** @var array<string, mixed> Parsed query parameters keyed by parameter name. */
    protected array $parameters = [];

    /**
     * Returns a list of fields set with the URL modifiers.
     * - e.g. ?fields['user']=first_name,last_name.
     *
     * @param  string|null  $resource
     * @return array<int|string, mixed>|null
     */
    public function getFields(?string $resource = null): ?array
    {
        $fields = $this->getParameters('fields', $resource);

        return is_array($fields) ? array_map('trim', $fields) : null;
    }

    /**
     * Returns a list of relation counts set with the URL modifiers.
     * - e.g. ?counts['user']=memberships.
     *
     * @param  string|null  $resource
     * @return array<int|string, mixed>|null
     */
    public function getCounts(?string $resource = null): ?array
    {
        $counts = $this->getParameters('counts', $resource);

        return is_array($counts) ? array_map('trim', $counts) : null;
    }

    /**
     * Returns a list of relation sums set with the URL modifiers.
     * - e.g. ?sums['account'][transaction]=amount.
     *
     * @param  string|null  $resource
     * @return array<int|string, mixed>|null
     */
    public function getSums(?string $resource = null): ?array
    {
        $sums = $this->getParameters('sums', $resource);

        return is_array($sums) ? $sums : null;
    }

    /**
     * Returns a list of relation averages set with the URL modifiers.
     * - e.g. ?averages['account'][transaction]=amount.
     *
     * @param  string|null  $resource
     * @return array<int|string, mixed>|null
     */
    public function getAverages(?string $resource = null): ?array
    {
        $averages = $this->getParameters('averages', $resource);

        return is_array($averages) ? $averages : null;
    }

    /**
     * Returns a list of filters set with the URL modifiers.
     *
     * @return array<int|string, mixed>
     */
    public function getFilters(): ?array
    {
        $filters = $this->getParameters('filters');

        return is_array($filters) ? $filters : [];
    }

    /**
     * Returns the desired order set with the URL modifiers.
     * - e.g. ?order=first_name,last_name:desc
     * - e.g. ?order=random.
     *
     * @return array<string, string>
     */
    public function getOrder(): array
    {
        $order = $this->getParameters('order');

        if (!is_array($order)) {
            return [];
        }

        /** @var array<string, string> $order */
        return $order;
    }

    /**
     * Returns the desired limit set with the URL modifiers.
     * - e.g. ?limit=x.
     *
     * @return int|null
     */
    public function getLimit(): ?int
    {
        $raw   = $this->getParameters('limit');
        $limit = is_numeric($raw) ? (int) $raw : 0;

        return $limit > 0 ? $limit : null;
    }

    /**
     * Returns the current page set with the URL modifiers.
     * - e.g. ?page=4.
     *
     * @return int
     */
    public function getPage(): int
    {
        $raw  = $this->getParameters('page');
        $page = is_numeric($raw) ? (int) $raw : 0;

        return $page > 0 ? $page : 1;
    }

    /**
     * Returns the current page cursor.
     * - e.g. ?cursor=eyJpZCI6MTAwfQ==.
     *
     * @return string|null
     */
    public function getCursor(): ?string
    {
        $cursor = $this->getParameters('cursor');

        return is_string($cursor) ? $cursor : null;
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
     * Extract and parse all parameters from the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    private function extractParameters(Request $request): array
    {
        $parameters = [];

        $parsers = [
            'page'     => fn ($value) => trim($value),
            'limit'    => fn ($value) => trim($value),
            'cursor'   => fn ($value) => $value,
            'fields'   => fn ($value) => $this->parseFields($value),
            'counts'   => fn ($value) => $this->parseCounts($value),
            'sums'     => fn ($value) => $this->parseSums($value),
            'averages' => fn ($value) => $this->parseAverages($value),
            'filters'  => fn ($value) => $this->parseFilters($value),
            'order'    => fn ($value) => $this->parseOrder($value),
        ];

        foreach ($parsers as $key => $parser) {
            if ($request->has($key)) {
                $parameters[$key] = $parser($request->input($key));
            }
        }

        return $parameters;
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

    /**
     * Validate the incoming request.
     *
     * @param  array<int|string, mixed>  $parameters
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validate(array $parameters): void
    {
        $validator = Validator::make($parameters, $this->buildValidationRules($parameters));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
