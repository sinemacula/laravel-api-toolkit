<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

use SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope;

/**
 * One media type of a documented request or response body.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class Body
{
    /** The media type a generated example is written for */
    private const string MEDIA_JSON = 'application/json';

    /**
     * Constructor.
     *
     * @param  string  $target
     * @param  list<int|string>  $keys
     * @param  array<array-key, mixed>  $media
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope|null  $scope
     */
    public function __construct(

        /** The operation and part the body belongs to, e.g. "POST /members request body" */
        public string $target,

        /** The keys leading from the document root to the media type object */
        public array $keys,

        /** The media type object */
        public array $media,

        /** The scope of the example generated for the body, null when it takes none */
        public ?ExampleScope $scope,
    ) {}

    /**
     * List every request and response body the document's operations carry.
     *
     * A JSON request or success body with a schema and no example of its own
     * takes a generated one.
     *
     * @param  array<array-key, mixed>  $document
     * @return list<self>
     */
    public static function in(array $document): array
    {
        $bodies = [];

        foreach (self::mapAt($document, 'paths') as $path => $operations) {
            foreach (is_array($operations) ? $operations : [] as $verb => $operation) {
                $bodies = [...$bodies, ...self::operationBodies(['paths', $path, $verb], strtoupper((string) $verb) . ' ' . $path, $operation)];
            }
        }

        return $bodies;
    }

    /**
     * Build the JSON pointer of the body's schema.
     *
     * @return string
     */
    public function schemaPointer(): string
    {
        return array_reduce([...$this->keys, 'schema'], SchemaDocument::append(...), '');
    }

    /**
     * List the request body and response bodies of an operation.
     *
     * @param  list<int|string>  $keys
     * @param  string  $name
     * @param  mixed  $operation
     * @return list<self>
     */
    private static function operationBodies(array $keys, string $name, mixed $operation): array
    {
        $bodies = self::media(is_array($operation) ? $operation['requestBody'] ?? null : null, [...$keys, 'requestBody'], $name . ' request body', ExampleScope::REQUEST);

        foreach (self::mapAt($operation, 'responses') as $status => $response) {

            $scope = is_int($status) && $status >= 200 && $status < 300 ? ExampleScope::RESPONSE : null;

            $bodies = [...$bodies, ...self::media($response, [...$keys, 'responses', $status], $name . ' response ' . $status, $scope)];
        }

        return $bodies;
    }

    /**
     * List the bodies of each media type a request body or response carries.
     *
     * @param  mixed  $owner
     * @param  list<int|string>  $keys
     * @param  string  $target
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\Enums\ExampleScope|null  $scope
     * @return list<self>
     */
    private static function media(mixed $owner, array $keys, string $target, ?ExampleScope $scope): array
    {
        $bodies = [];

        foreach (self::mapAt($owner, 'content') as $type => $media) {

            if (!is_array($media)) {
                continue;
            }

            $generates = $type === self::MEDIA_JSON
                && is_array($media['schema'] ?? null)
                && !array_key_exists('examples', $media)
                && !array_key_exists('example', $media);

            $bodies[] = new self($target, [...$keys, 'content', $type], $media, $generates ? $scope : null);
        }

        return $bodies;
    }

    /**
     * Read the array held under a key of a node, empty when either is not one.
     *
     * @param  mixed  $node
     * @param  string  $key
     * @return array<array-key, mixed>
     */
    private static function mapAt(mixed $node, string $key): array
    {
        return is_array($node) && is_array($node[$key] ?? null) ? $node[$key] : [];
    }
}
