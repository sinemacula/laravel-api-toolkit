<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Examples;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;

/**
 * Validates values against the schemas of an assembled document, offline.
 *
 * Only the document itself is registered, so no reference is fetched. No
 * default fills a missing property and no extension keyword takes effect, so
 * document content cannot change how a value validates. An empty enum, which
 * the validator cannot parse, is registered as the false schema it equals.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SchemaDocument
{
    /** The identifier the document is registered under */
    private const string DOCUMENT_ID = 'https://openapi.invalid/document.json';

    /**
     * Constructor.
     *
     * @param  array<array-key, mixed>  $nodes
     * @param  \Opis\JsonSchema\CompliantValidator  $validator
     */
    private function __construct(

        /** The document as decoded arrays, for reading its nodes. */
        private array $nodes,

        /** The validator the document is registered with. */
        private CompliantValidator $validator,
    ) {}

    /**
     * Register an assembled document for validation.
     *
     * @param  array<string, mixed>  $document
     * @return self
     */
    public static function of(array $document): self
    {
        $resolver = new SchemaResolver;

        $resolver->registerRaw((object) self::encode(self::withoutEmptyEnums($document)), self::DOCUMENT_ID);

        /** @var array<array-key, mixed> $nodes */
        $nodes = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return new self($nodes, new CompliantValidator(new SchemaLoader(new SchemaParser, $resolver)));
    }

    /**
     * Append an unescaped segment to a JSON pointer.
     *
     * @param  string  $pointer
     * @param  int|string  $segment
     * @return string
     */
    public static function append(string $pointer, int|string $segment): string
    {
        return $pointer . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
    }

    /**
     * Read the node at a JSON pointer, null when there is none.
     *
     * @param  string  $pointer
     * @return mixed
     */
    public function node(string $pointer): mixed
    {
        $node = $this->nodes;

        foreach ($this->segments($pointer) as $segment) {

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Resolve a node's pointer to that of the schema it references, if any.
     *
     * @param  string  $pointer
     * @return string
     */
    public function target(string $pointer): string
    {
        $reference = $this->node(self::append($pointer, '$ref'));

        return is_string($reference) ? substr($reference, 1) : $pointer;
    }

    /**
     * Collect a schema and the conjuncts its allOf names, a referencing one as
     * its target, keyed by pointer, onto those already collected.
     *
     * @param  string  $pointer
     * @param  array<array-key, mixed>  $schema
     * @param  array<string, array<array-key, mixed>>  $parts
     * @return array<string, array<array-key, mixed>>
     */
    public function conjuncts(string $pointer, array $schema, array $parts = []): array
    {
        $parts[$pointer] = $schema;

        foreach (array_keys(is_array($schema['allOf'] ?? null) ? $schema['allOf'] : []) as $index) {

            $conjunct = $this->target(self::append(self::append($pointer, 'allOf'), $index));
            $node     = $this->node($conjunct);

            if (!is_array($node) || array_key_exists($conjunct, $parts)) {
                continue;
            }

            $parts = $this->conjuncts($conjunct, $node, $parts);
        }

        return $parts;
    }

    /**
     * Describe where and why a value fails the schema at a JSON pointer, or
     * return null when it validates.
     *
     * @param  mixed  $value
     * @param  string  $pointer
     * @return string|null
     */
    public function mismatch(mixed $value, string $pointer): ?string
    {
        $fragment = implode('/', array_map(rawurlencode(...), explode('/', $pointer)));
        $error    = $this->validator->validate(self::encode($value), self::DOCUMENT_ID . '#' . $fragment)->error();

        if ($error === null) {
            return null;
        }

        while ($error->subErrors() !== []) {
            $error = $error->subErrors()[0];
        }

        $formatter = new ErrorFormatter;
        $location  = $formatter->formatErrorKey($error);

        return sprintf('%s: %s', $location === '/' ? 'at the root' : 'at ' . $location, $formatter->formatErrorMessage($error));
    }

    /**
     * Replace every schema with an empty enum by the false schema.
     *
     * @param  mixed  $node
     * @return mixed
     */
    private static function withoutEmptyEnums(mixed $node): mixed
    {
        if ($node instanceof \stdClass) {
            return (object) self::withoutEmptyEnums((array) $node);
        }

        if (!is_array($node)) {
            return $node;
        }

        $node = array_map(self::withoutEmptyEnums(...), $node);

        return ($node['enum'] ?? null) === [] ? false : $node;
    }

    /**
     * Encode a value as the JSON it is written as.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function encode(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Split a JSON pointer into its unescaped segments.
     *
     * @param  string  $pointer
     * @return list<string>
     */
    private function segments(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        return array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', substr($pointer, 1)),
        );
    }
}
