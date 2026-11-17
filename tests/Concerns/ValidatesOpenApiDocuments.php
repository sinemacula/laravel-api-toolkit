<?php

declare(strict_types = 1);

namespace Tests\Concerns;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Formats\UriFormats;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\FormatResolver;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\ValidationResult;

/**
 * Validates exported documents against the vendored OpenAPI 3.1 meta-schemas,
 * which also hold every embedded Schema Object to the OpenAPI dialect.
 *
 * Validation runs in the spec-compliant mode, so no schema default is written
 * into the validated data; the fixtures README lists the remaining adaptations.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait ValidatesOpenApiDocuments
{
    /** @var string The identifier of the OpenAPI 3.1 meta-schema that also validates every embedded schema. */
    private const string OPENAPI_META_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema-base/2022-10-07';

    /** @var string The identifier under which an exported document is registered as a schema. */
    private const string EXPORTED_DOCUMENT_ID = 'https://api.example.com/openapi.json';

    /** @var list<string> The vendored meta-schema fixtures, each registered under its own `$id`. */
    private const array OPENAPI_META_SCHEMA_FIXTURES = [
        'json-schema-2020-12.json',
        'json-schema-2020-12-meta-core.json',
        'json-schema-2020-12-meta-applicator.json',
        'json-schema-2020-12-meta-unevaluated.json',
        'json-schema-2020-12-meta-validation.json',
        'json-schema-2020-12-meta-meta-data.json',
        'json-schema-2020-12-meta-format-annotation.json',
        'json-schema-2020-12-meta-content.json',
        'openapi-3.1-dialect-base.json',
        'openapi-3.1-meta-base.json',
        'openapi-3.1-schema.json',
        'openapi-3.1-schema-base.json',
    ];

    /**
     * Validate a document against the OpenAPI 3.1 meta-schema.
     *
     * @param  array<string, mixed>  $document
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateDocument(array $document): ValidationResult
    {
        return $this->openApiValidator()->validate(Helper::toJSON($document), self::OPENAPI_META_SCHEMA_ID);
    }

    /**
     * Validate a raw JSON document against the OpenAPI 3.1 meta-schema.
     *
     * Decoding to objects keeps an empty `paths` object distinct from an empty
     * array.
     *
     * @param  string  $json
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateJson(string $json): ValidationResult
    {
        return $this->openApiValidator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), self::OPENAPI_META_SCHEMA_ID);
    }

    /**
     * Validate a payload against the exported schema at a JSON pointer.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $pointer
     * @param  mixed  $payload
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateAgainstExported(array $document, string $pointer, mixed $payload): ValidationResult
    {
        $validator = new CompliantValidator;
        $resolver  = $validator->resolver();
        $exported  = Helper::toJSON($document);

        assert($resolver instanceof SchemaResolver);
        assert(is_object($exported));

        $resolver->registerRaw($exported, self::EXPORTED_DOCUMENT_ID);

        return $validator->validate(Helper::toJSON($payload), self::EXPORTED_DOCUMENT_ID . '#' . $pointer);
    }

    /**
     * Assert that a document validates against the OpenAPI 3.1 meta-schema.
     *
     * @param  array<string, mixed>  $document
     * @return void
     */
    private function assertValidDocument(array $document): void
    {
        $result = $this->validateDocument($document);

        self::assertTrue($result->isValid(), 'The document is not valid OpenAPI 3.1: ' . $this->formatErrors($result));
    }

    /**
     * Assert every reference is local and resolves to an existing component.
     *
     * @param  array<string, mixed>  $document
     * @return void
     */
    private function assertReferencesResolve(array $document): void
    {
        $references = $this->collectReferences($document);

        self::assertNotEmpty($references);

        foreach ($references as $reference) {

            self::assertStringStartsWith('#/', $reference, 'Only a local reference can be resolved within the document.');
            self::assertNotNull(
                $this->resolveReference($document, $reference),
                sprintf('The reference "%s" resolves to no component.', $reference),
            );
        }
    }

    /**
     * Format a validation result's errors for a failure message.
     *
     * @param  \Opis\JsonSchema\ValidationResult  $result
     * @return string
     */
    private function formatErrors(ValidationResult $result): string
    {
        $error = $result->error();

        if ($error === null) {
            return '(no error)';
        }

        return json_encode((new ErrorFormatter)->format($error), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Build a validator with every vendored meta-schema registered and adapted.
     *
     * A uri-reference is checked after substituting server variable templates
     * with a name, or a digit so a templated port holds.
     *
     * @return \Opis\JsonSchema\CompliantValidator
     */
    private function openApiValidator(): CompliantValidator
    {
        $validator = new CompliantValidator;
        $resolver  = $validator->resolver();
        $formats   = $validator->parser()->getFormatResolver();

        assert($resolver instanceof SchemaResolver);
        assert($formats instanceof FormatResolver);

        foreach (self::OPENAPI_META_SCHEMA_FIXTURES as $fixture) {

            $schema = $this->adaptedMetaSchema($fixture);

            assert(is_string($schema['$id']));

            $resolver->registerRaw(json_encode($schema, JSON_THROW_ON_ERROR), $schema['$id']);
        }

        $formats->registerCallable('string', 'uri-reference', static function (string $value): bool {
            foreach (['variable', '0'] as $substitute) {
                if (UriFormats::uriReference((string) preg_replace('/\{[^{}]+\}/', $substitute, $value))) {
                    return true;
                }
            }

            return false;
        });

        return $validator;
    }

    /**
     * Load a vendored meta-schema with its dynamic meta references made static.
     *
     * @param  string  $fixture
     * @return array<string, mixed>
     */
    private function adaptedMetaSchema(string $fixture): array
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/OpenApi/' . $fixture);

        assert(is_string($contents));

        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $this->replaceDynamicMetaReferences($schema);

        return $schema;
    }

    /**
     * Rewrite every `$dynamicRef: "#meta"` to the static reference it resolves
     * to under the schema-base meta-schema.
     *
     * @param  array<array-key, mixed>  $node
     * @return void
     */
    private function replaceDynamicMetaReferences(array &$node): void
    {
        if (($node['$dynamicRef'] ?? null) === '#meta') {
            unset($node['$dynamicRef']);
            $node['$ref'] = self::OPENAPI_META_SCHEMA_ID . '#/$defs/schema';
        }

        foreach ($node as &$value) {

            if (!is_array($value)) {
                continue;
            }

            $this->replaceDynamicMetaReferences($value);
        }
    }

    /**
     * Collect every distinct reference the node carries, at any depth.
     *
     * @param  mixed  $node
     * @return array<int, string>
     */
    private function collectReferences(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $references = [];

        foreach ($node as $key => $value) {

            if ($key === '$ref' && is_string($value)) {

                $references[] = $value;
                continue;
            }

            $references = [...$references, ...$this->collectReferences($value)];
        }

        return array_values(array_unique($references));
    }

    /**
     * Resolve a local reference against the document, or null when missing.
     *
     * @param  array<string, mixed>  $document
     * @param  string  $reference
     * @return mixed
     */
    private function resolveReference(array $document, string $reference): mixed
    {
        $node = $document;

        foreach (explode('/', substr($reference, 2)) as $segment) {

            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
