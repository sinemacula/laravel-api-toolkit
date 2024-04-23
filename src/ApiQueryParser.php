<?php

namespace SineMacula\ApiToolkit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * API query parser.
 *
 * Extract API parameters supplied within the query string of a request.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
class ApiQueryParser
{
    /** @var array */
    protected array $parameters = [];

    /**
     * Returns a list of fields set with the URL modifiers.
     *  - e.g. ?fields['user']=first_name,last_name
     *
     * @param  string|null  $resource
     * @return array
     */
    public function getFields(?string $resource = null): array
    {
        if ($resource) {
            return array_map('trim', $this->getParameters('fields', $resource) ?? []);
        } else {
            return $this->getParameters('fields') ?? [];
        }
    }

    /**
     * Returns a list of filters set with the URL modifiers.
     *
     * @return array|null
     */
    public function getFilters(): ?array
    {
        return $this->getParameters('filters') ?? [];
    }

    /**
     * Returns the desired order set with the URL modifiers.
     *  - e.g. ?order=first_name,last_name:desc
     *  - e.g. ?order=random
     *
     * @return array
     */
    public function getOrder(): array
    {
        return $this->getParameters('order') ?? [];
    }

    /**
     * Returns the desired limit set with the URL modifiers.
     *  - e.g. ?limit=x
     *
     * @return int
     */
    public function getLimit(): int
    {
        $limit = (int) $this->getParameters('limit');

        return $limit > 0 ? $limit : Config::get('api-toolkit.parser.defaults.limit');
    }

    /**
     * Returns the current page set with the URL modifiers.
     *  - e.g. ?page=4
     *
     * @return int|null
     */
    public function getPage(): ?int
    {
        $page = (int) $this->getParameters('page');

        return $page > 0 ? $page : 1;
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

        $this->parameters = array_map('trim', $request->only(['page', 'limit']));

        if ($request->has('fields')) {
            $this->parameters['fields'] = $this->parseFields($request->input('fields'));
        }

        if ($request->has('filters')) {
            $this->parameters['filters'] = $this->parseFilters($request->input('filters'));
        }

        if ($request->has('order')) {
            $this->parameters['order'] = $this->parseOrder($request->input('order'));
        }
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
     * @param  array  $parameters
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function validate(array $parameters): void
    {
        $validator = Validator::make($parameters, $this->buildValidationRulesFromParameters($parameters));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Extract the field parameters from the query string.
     *
     * @param  string|array  $query
     * @return array
     */
    private function parseFields(string|array $query): array
    {
        return is_array($query) ?
            array_map(function ($value) {
                return array_map('trim', explode(',', $value));
            }, $query)
            : array_map('trim', explode(',', $query));
    }

    /**
     * Extract the filter parameters from the query string.
     *
     * @param  string  $query
     * @return array
     */
    private function parseFilters(string $query): array
    {
        return json_decode($query, true) ?? [];
    }

    /**
     * Extract the order parameters from the query string.
     *
     * @param  string  $query
     * @return array
     */
    private function parseOrder(string $query): array
    {
        $order  = [];
        $fields = explode(',', $query);

        if (!empty(array_filter($fields))) {
            foreach ($fields as $field) {
                $order_parts    = explode(':', $field, 2);
                $column         = $order_parts[0];
                $direction      = $order_parts[1] ?? 'asc';
                $order[$column] = $direction;
            }
        }

        return $order;
    }

    /**
     * Build the validation rules from the given parameters.
     *
     * @param  array  $parameters
     * @return array
     */
    private function buildValidationRulesFromParameters(array $parameters): array
    {
        $rules = [
            'fields'  => 'string',
            'filters' => 'json',
            'order'   => 'string',
            'page'    => 'integer|min:1',
            'limit'   => 'integer|min:1'
        ];

        if (isset($parameters['fields']) && is_array($parameters['fields'])) {
            $rules['fields']   = 'array';
            $rules['fields.*'] = 'string';
        }

        return $rules;
    }
}
