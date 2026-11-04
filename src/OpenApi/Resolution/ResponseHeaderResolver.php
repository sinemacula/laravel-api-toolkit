<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader;

/**
 * Resolves the response headers #[ResponseHeader] directives declare.
 *
 * An action's success headers are read from the action method first, then from
 * its controller class and each of the controller's parent classes; an
 * exception's headers are read from the exception class and each of its parent
 * classes, since an attribute is not inherited by the language itself. Each
 * header becomes an OpenAPI header object carrying its schema and, when one is
 * given, its description, keyed by the header name as declared. Header names
 * compare case-insensitively and the first declaration wins, so the nearest
 * declaration overrides a header its controller or parent class also declares,
 * and the result follows declaration order, nearest first.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ResponseHeaderResolver
{
    /**
     * Resolve the headers declared for the action's success response.
     *
     * @param  class-string|null  $controllerClass
     * @param  string  $action
     * @return array<string, array<string, mixed>>
     */
    public function forAction(?string $controllerClass, string $action): array
    {
        if ($controllerClass === null) {
            return [];
        }

        $methodHeaders = method_exists($controllerClass, $action)
            ? $this->headerObjects((new \ReflectionMethod($controllerClass, $action))->getAttributes(ResponseHeader::class))
            : [];

        return $this->merge($methodHeaders, $this->forClass($controllerClass));
    }

    /**
     * Resolve the headers the exception declares it may set.
     *
     * @param  class-string<\SineMacula\ApiToolkit\Exceptions\ApiException>  $exceptionClass
     * @return array<string, array<string, mixed>>
     */
    public function forException(string $exceptionClass): array
    {
        return $this->forClass($exceptionClass);
    }

    /**
     * Merge two header maps, keeping a header of the second only when the first
     * carries no header of the same name, compared case-insensitively.
     *
     * @param  array<string, array<string, mixed>>  $headers
     * @param  array<string, array<string, mixed>>  $additional
     * @return array<string, array<string, mixed>>
     */
    public function merge(array $headers, array $additional): array
    {
        $taken = array_change_key_case($headers);

        foreach ($additional as $name => $header) {

            if (array_key_exists(strtolower($name), $taken)) {
                continue;
            }

            $headers[$name]           = $header;
            $taken[strtolower($name)] = $header;
        }

        return $headers;
    }

    /**
     * Resolve the headers declared on the class and each of its parents.
     *
     * @param  class-string  $class
     * @return array<string, array<string, mixed>>
     */
    private function forClass(string $class): array
    {
        $headers = [];

        for ($reflection = new \ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $headers = $this->merge($headers, $this->headerObjects($reflection->getAttributes(ResponseHeader::class)));
        }

        return $headers;
    }

    /**
     * Build the header objects for the directives, keeping the first of any
     * directives that repeat a name.
     *
     * @param  array<int, \ReflectionAttribute<\SineMacula\ApiToolkit\OpenApi\Attributes\ResponseHeader>>  $attributes
     * @return array<string, array<string, mixed>>
     */
    private function headerObjects(array $attributes): array
    {
        $headers = [];

        foreach ($attributes as $attribute) {

            $directive = $attribute->newInstance();
            $header    = $directive->description === '' ? [] : ['description' => $directive->description];

            $headers = $this->merge($headers, [$directive->name => [...$header, 'schema' => $directive->schema]]);
        }

        return $headers;
    }
}
