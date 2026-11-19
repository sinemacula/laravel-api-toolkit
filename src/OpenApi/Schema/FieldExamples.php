<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesFieldExamples;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;

/**
 * Writes the example values a rules source gives its fields onto the schema
 * translated from its rules.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FieldExamples
{
    /** The message for an example whose key names no field */
    private const string UNKNOWN_MESSAGE = 'The field example [%s] on %s names no field its rules declare.';

    /** The message for an example whose key names a prohibited field */
    private const string PROHIBITED_MESSAGE = 'The field example [%s] on %s names a field its rules prohibit.';

    /**
     * Write each example the source gives onto the node its rules key names.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  string  $source
     * @return array<array-key, mixed>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException
     */
    public function apply(array $schema, string $source): array
    {
        if (!is_subclass_of($source, DefinesFieldExamples::class)) {
            return $schema;
        }

        foreach ($source::fieldExamples() as $key => $value) {

            $path = $this->locate($schema, explode('.', (string) $key))
                ?? throw new ExampleDeclarationException(sprintf(self::UNKNOWN_MESSAGE, $key, $source));

            if ($this->child($schema, $path) === false) {
                throw new ExampleDeclarationException(sprintf(self::PROHIBITED_MESSAGE, $key, $source));
            }

            $schema = $this->write($schema, $path, $value);
        }

        return $schema;
    }

    /**
     * Find the keys leading from a node to the field a rules key names, through
     * the conjuncts an item schema is split into.
     *
     * @param  mixed  $node
     * @param  list<string>  $segments
     * @return list<int|string>|null
     */
    private function locate(mixed $node, array $segments): ?array
    {
        if ($segments === []) {
            return [];
        }

        foreach ($this->steps($node, $segments) as [$keys, $rest]) {

            $path = $this->locate($this->child($node, $keys), $rest);

            if ($path !== null) {
                return [...$keys, ...$path];
            }
        }

        return null;
    }

    /**
     * List the steps a rules key's first segment may take from a node, each
     * with the segments left: into the property or items it names, else into a
     * conjunct holding them.
     *
     * @param  mixed  $node
     * @param  non-empty-list<string>  $segments
     * @return list<array{list<int|string>, list<string>}>
     */
    private function steps(mixed $node, array $segments): array
    {
        if (!is_array($node)) {
            return [];
        }

        $keys  = $segments[0]               === '*' ? ['items'] : ['properties', $segments[0]];
        $steps = $this->child($node, $keys) === null ? [] : [[$keys, array_slice($segments, 1)]];

        foreach (array_keys(is_array($node['allOf'] ?? null) ? $node['allOf'] : []) as $index) {
            $steps[] = [['allOf', $index], $segments];
        }

        return $steps;
    }

    /**
     * Read the node a list of keys leads to, null when there is none.
     *
     * @param  mixed  $node
     * @param  list<int|string>  $keys
     * @return mixed
     */
    private function child(mixed $node, array $keys): mixed
    {
        foreach ($keys as $key) {

            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }

    /**
     * Write an example onto the node a list of keys leads to.
     *
     * @param  array<array-key, mixed>  $node
     * @param  list<int|string>  $keys
     * @param  mixed  $example
     * @return array<array-key, mixed>
     */
    private function write(array $node, array $keys, mixed $example): array
    {
        if ($keys === []) {
            return [...$node, 'examples' => [$example]];
        }

        $key = array_shift($keys);

        $node[$key] = $this->write((array) $node[$key], $keys, $example);

        return $node;
    }
}
