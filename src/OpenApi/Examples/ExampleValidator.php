<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleMismatchException;
use SineMacula\ApiToolkit\OpenApi\Schema\SchemaKeywords;

/**
 * Validates the examples of a document's operation bodies and schemas against
 * the schema beside each.
 *
 * A media example is checked against its media type's schema, a referenced one
 * through its reference, and a schema's own examples against that schema.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ExampleValidator
{
    /** The message for an example its schema rejects */
    public const string MISMATCH_MESSAGE = 'The %s example [%s] for %s in the [%s] audience does not match its schema %s';

    /**
     * Validate every example of the document against its registration.
     *
     * The examples are read from the document itself, where an empty JSON
     * object is still an object.
     *
     * @param  array<array-key, mixed>  $document
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument  $schemas
     * @param  string  $audience
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleMismatchException
     */
    public function validate(array $document, SchemaDocument $schemas, string $audience): void
    {
        foreach (Body::in($document) as $body) {
            foreach ($this->mediaExamples($schemas, $body->media) as $name => [$kind, $value]) {

                $mismatch = $schemas->mismatch($value, $body->schemaPointer());

                if ($mismatch !== null) {
                    throw new ExampleMismatchException(sprintf(self::MISMATCH_MESSAGE, $kind, $name, $body->target, $audience, $mismatch));
                }
            }
        }

        foreach ($this->schemaExamples($document, '') as [$pointer, $name, $value]) {

            $mismatch = $schemas->mismatch($value, $pointer);

            if ($mismatch !== null) {
                throw new ExampleMismatchException(sprintf(self::MISMATCH_MESSAGE, 'schema', $name, '#' . $pointer, $audience, $mismatch));
            }
        }
    }

    /**
     * List a media type's examples by name, each with its kind and value.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument  $schemas
     * @param  array<array-key, mixed>  $media
     * @return array<array-key, array{string, mixed}>
     */
    private function mediaExamples(SchemaDocument $schemas, array $media): array
    {
        $examples = array_key_exists('example', $media) ? ['example' => ['declared', $media['example']]] : [];

        foreach (is_array($media['examples'] ?? null) ? $media['examples'] : [] as $name => $example) {
            $examples[$name] = $this->mediaExample($schemas, (array) $example);
        }

        return $examples;
    }

    /**
     * Read an Example Object's kind and value: an error example through its
     * reference, else a declared one.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Examples\SchemaDocument  $schemas
     * @param  array<array-key, mixed>  $example
     * @return array{string, mixed}
     */
    private function mediaExample(SchemaDocument $schemas, array $example): array
    {
        if (!is_string($example['$ref'] ?? null)) {
            return ['declared', $example['value'] ?? null];
        }

        return ['error', $schemas->node(SchemaDocument::append(substr($example['$ref'], 1), 'value'))];
    }

    /**
     * Yield the pointer, name and value of every example a schema carries,
     * walking the document to each schema it holds.
     *
     * @param  array<array-key, mixed>  $node
     * @param  string  $pointer
     * @return iterable<array{string, int|string, mixed}>
     */
    private function schemaExamples(array $node, string $pointer): iterable
    {
        foreach ($node as $key => $value) {

            if (!is_array($value) || in_array($key, SchemaKeywords::EXAMPLE, true)) {
                continue;
            }

            $at = SchemaDocument::append($pointer, $key);

            yield from match (true) {
                $key     === 'schema'                            => $this->examplesIn($value, $at),
                $pointer === '/components' && $key === 'schemas' => $this->examplesInMap($value, $at),
                default                                          => $this->schemaExamples($value, $at),
            };
        }
    }

    /**
     * Yield the examples of a schema and of every subschema it nests.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  string  $pointer
     * @return iterable<array{string, int|string, mixed}>
     */
    private function examplesIn(array $schema, string $pointer): iterable
    {
        foreach (is_array($schema['examples'] ?? null) ? $schema['examples'] : [] as $index => $example) {
            yield [$pointer, $index, $example];
        }

        if (array_key_exists('example', $schema)) {
            yield [$pointer, 'example', $schema['example']];
        }

        foreach ($schema as $keyword => $value) {

            if (!is_array($value)) {
                continue;
            }

            $at = SchemaDocument::append($pointer, $keyword);

            if (in_array($keyword, SchemaKeywords::SCHEMA, true)) {
                yield from $this->examplesIn($value, $at);

                continue;
            }

            if (!in_array($keyword, [...SchemaKeywords::SCHEMA_LIST, ...SchemaKeywords::SCHEMA_MAP], true)) {
                continue;
            }

            yield from $this->examplesInMap($value, $at);
        }
    }

    /**
     * Yield the examples of every schema in a list or map of schemas.
     *
     * @param  array<array-key, mixed>  $schemas
     * @param  string  $pointer
     * @return iterable<array{string, int|string, mixed}>
     */
    private function examplesInMap(array $schemas, string $pointer): iterable
    {
        foreach ($schemas as $key => $schema) {

            if (!is_array($schema)) {
                continue;
            }

            yield from $this->examplesIn($schema, SchemaDocument::append($pointer, $key));
        }
    }
}
