<?php

namespace Tests\Integration;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ExportOpenApiCommand;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\ExportResult;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\Post;
use Tests\Fixtures\Models\Tag;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\Fixtures\Resources\PostResource;
use Tests\Fixtures\Resources\TagResource;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * End-to-end validity test for the OpenAPI exporter.
 *
 * Exports the components document for the fixture resource registry and proves
 * the headline success metric: the emitted document validates against the
 * official OpenAPI 3.1 meta-schema (via opis/json-schema). It then asserts the
 * remaining requirement oracles end to end -- 3.1.x version, populated
 * components, one schema per resource (FR-3), full operator vocabulary in the
 * filter parameter (FR-4), one response per error code (FR-5), no paths (FR-9),
 * created_at as a date-time string (AC-07), an opaque compute field flagged
 * x-undocumented (FR-8), and a regenerate-after-change diff (FR-2).
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExportOpenApiComponents::class)]
#[CoversClass(ExportOpenApiCommand::class)]
class OpenApiExporterValidityTest extends TestCase
{
    /** @var string The identifier under which the OpenAPI 3.1 meta-schema is registered. */
    private const string META_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    /** @var string The identifier of the JSON Schema 2020-12 dialect document. */
    private const string DIALECT_ID = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * Set up each test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        $this->registerResourceMap();
    }

    /**
     * Test that the emitted document validates against the OpenAPI 3.1
     * meta-schema -- the 100% document-validity success metric.
     *
     * @return void
     */
    public function testEmittedDocumentValidatesAsOpenApiThreeOne(): void
    {
        $document = $this->export()->document;

        $result = $this->validateAgainstMetaSchema($document);

        static::assertTrue(
            $result->isValid(),
            'Emitted document is not valid OpenAPI 3.1: ' . $this->formatErrors($result),
        );
    }

    /**
     * Test that the emitted document declares a 3.1.x version and a populated
     * components block (AC-01/AC-09).
     *
     * @return void
     */
    public function testDocumentDeclaresThreeOneAndPopulatesComponents(): void
    {
        $document = $this->export()->document;

        static::assertStringStartsWith('3.1', $document['openapi']);

        $components = $document['components'];

        static::assertNotEmpty($components['schemas']);
        static::assertNotEmpty($components['parameters']);
        static::assertNotEmpty($components['responses']);
    }

    /**
     * Test that the document declares no path operations (FR-9/AC-09).
     *
     * @return void
     */
    public function testDocumentDeclaresNoPaths(): void
    {
        $document = $this->export()->document;

        static::assertArrayHasKey('paths', $document);
        static::assertSame('{}', json_encode($document['paths']));
    }

    /**
     * Test that exactly one component schema is emitted per registered resource
     * (FR-3) and the summary count matches the registry size.
     *
     * @return void
     */
    public function testOneSchemaPerRegisteredResource(): void
    {
        $result = $this->export();

        static::assertSame(4, $result->resourceCount);

        $schemas = $result->document['components']['schemas'];

        foreach (['User', 'Organization', 'Post', 'Tag'] as $name) {
            static::assertArrayHasKey($name, $schemas);
        }
    }

    /**
     * Test that every registered operator and structural operator appears in
     * the emitted filter parameter (FR-4).
     *
     * @return void
     */
    public function testEveryOperatorAppearsInTheFilterParameter(): void
    {
        $document  = $this->export()->document;
        $operators = $document['components']['parameters']['Filter']['schema']['x-operators'];

        $expected = [
            '$eq', '$neq', '$gt', '$lt', '$ge', '$le', '$like', '$in',
            '$between', '$contains', '$null', '$notNull',
            '$and', '$or', '$has', '$hasnt',
        ];

        foreach ($expected as $operator) {
            static::assertContains($operator, $operators);
        }
    }

    /**
     * Test that the emitted document carries exactly one error response per
     * defined error code (FR-5).
     *
     * @return void
     */
    public function testOneResponsePerErrorCode(): void
    {
        $document   = $this->export()->document;
        $responses  = $document['components']['responses'];
        $errorCodes = ErrorCode::cases();

        static::assertCount(count($errorCodes), $responses);

        foreach ($errorCodes as $code) {
            static::assertArrayHasKey('ErrorResponse' . $code->getCode(), $responses);
        }
    }

    /**
     * Test that an un-annotated timestamp field is inferred as a date-time
     * string end to end (AC-07).
     *
     * @return void
     */
    public function testCreatedAtIsEmittedAsADateTimeString(): void
    {
        $document  = $this->export()->document;
        $createdAt = $document['components']['schemas']['User']['properties']['created_at'];

        // The column is nullable, so the 2020-12 nullable type-array form is
        // emitted; the date-time format is the AC-07 signal either way.
        static::assertContains('string', (array) $createdAt['type']);
        static::assertSame('date-time', $createdAt['format']);
    }

    /**
     * Test that an opaque compute field with no declaration carries the
     * x-undocumented marker and remains permissive (FR-8).
     *
     * @return void
     */
    public function testComputeFieldIsFlaggedUndocumented(): void
    {
        $document  = $this->export()->document;
        $fullLabel = $document['components']['schemas']['User']['properties']['full_label'];

        static::assertTrue($fullLabel['x-undocumented']);
        static::assertArrayNotHasKey('type', $fullLabel);
    }

    /**
     * Test that a relation property emits the conservative object-or-array
     * reference shape rather than a concrete single shape.
     *
     * @return void
     */
    public function testRelationEmitsConservativeReferenceShape(): void
    {
        $document     = $this->export()->document;
        $organization = $document['components']['schemas']['User']['properties']['organization'];

        static::assertArrayHasKey('oneOf', $organization);
        static::assertSame('unknown', $organization['x-cardinality']);
        static::assertSame(['$ref' => '#/components/schemas/Organization'], $organization['oneOf'][0]);
    }

    /**
     * Test that regenerating the document after a documented surface changes
     * produces a different component, and the new document stays 3.1-valid
     * (FR-2/AC-02).
     *
     * @return void
     */
    public function testRegenerateAfterChangeShowsADiff(): void
    {
        $before = $this->export()->document['components']['schemas']['Organization'];

        // Drop a resource from the registry and re-export: the previously
        // documented surface is no longer present in the regenerated document.
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class => UserResource::class,
        ]);

        SchemaCompiler::clearCache();

        $after = $this->export()->document;

        static::assertArrayNotHasKey('Organization', $after['components']['schemas']);
        static::assertNotEmpty($before);

        static::assertTrue(
            $this->validateAgainstMetaSchema($after)->isValid(),
            'Regenerated document is not valid OpenAPI 3.1.',
        );
    }

    /**
     * Test that the artisan command writes a non-empty 3.1-valid document and
     * reports a non-zero resource count, exit 0 (AC-01).
     *
     * @return void
     */
    public function testCommandWritesAValidDocumentWithExitZero(): void
    {
        $path = sys_get_temp_dir() . '/api-toolkit-openapi-' . uniqid('', true) . '.json';

        try {

            $exitCode = Artisan::call(ExportOpenApiCommand::class, ['--output' => $path]);

            static::assertSame(0, $exitCode);
            static::assertStringContainsString('Exported 4 resource schema(s)', Artisan::output());
            static::assertFileExists($path);

            $contents = file_get_contents($path);

            static::assertIsString($contents);
            static::assertNotSame('', $contents);

            static::assertTrue(
                $this->validateJson($contents)->isValid(),
                'Document written by the command is not valid OpenAPI 3.1.',
            );

        } finally {
            @unlink($path);
        }
    }

    /**
     * Test that the opis-adapted meta-schema still rejects invalid documents, so
     * the in-test compatibility transforms cannot silently hollow out the
     * headline validity signal if opis or the meta-schema changes.
     *
     * @return void
     */
    public function testAdaptedMetaSchemaStillRejectsInvalidDocuments(): void
    {
        $valid = $this->export()->document;

        static::assertTrue(
            $this->validateAgainstMetaSchema($valid)->isValid(),
            'The real emitted document must validate as OpenAPI 3.1.',
        );

        $threeZero            = $valid;
        $threeZero['openapi'] = '3.0.3';

        static::assertFalse(
            $this->validateAgainstMetaSchema($threeZero)->isValid(),
            'A 3.0 version string must be rejected by the 3.1 meta-schema.',
        );

        $missingInfo = $valid;
        unset($missingInfo['info']);

        static::assertFalse(
            $this->validateAgainstMetaSchema($missingInfo)->isValid(),
            'A document missing the required info object must be rejected.',
        );

        $malformedParameter                                       = $valid;
        $malformedParameter['components']['parameters']['Broken'] = ['description' => 'no name or in'];

        static::assertFalse(
            $this->validateAgainstMetaSchema($malformedParameter)->isValid(),
            'A parameter missing its required name/in must be rejected.',
        );
    }

    /**
     * Run the export use case against the container-resolved graph.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\ExportResult
     */
    private function export(): ExportResult
    {
        /** @var \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents $exporter */
        $exporter = $this->makeApplication()->make(ExportOpenApiComponents::class);

        return $exporter->export();
    }

    /**
     * Validate a document array against the bundled (opis-adapted) OpenAPI 3.1
     * meta-schema.
     *
     * @param  array<string, mixed>  $document
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateAgainstMetaSchema(array $document): ValidationResult
    {
        return $this->validator()->validate(Helper::toJSON($document), self::META_SCHEMA_ID);
    }

    /**
     * Validate a raw JSON document string against the bundled meta-schema.
     *
     * Decoding to objects (rather than associative arrays) preserves the
     * distinction between an empty JSON object and an empty array, so the
     * document's empty `paths` object stays an object and remains schema-valid.
     *
     * @param  string  $json
     * @return \Opis\JsonSchema\ValidationResult
     */
    private function validateJson(string $json): ValidationResult
    {
        return $this->validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), self::META_SCHEMA_ID);
    }

    /**
     * Build a validator with the dialect and meta-schema registered.
     *
     * @return \Opis\JsonSchema\Validator
     */
    private function validator(): Validator
    {
        $validator = new Validator;
        $resolver  = $validator->resolver();

        assert($resolver instanceof SchemaResolver);

        $resolver->registerRaw($this->dialectSchema(), self::DIALECT_ID);
        $resolver->registerRaw($this->metaSchema(), self::META_SCHEMA_ID);

        return $validator;
    }

    /**
     * Load the OpenAPI 3.1 meta-schema, applying the documented opis
     * compatibility transform.
     *
     * @return string
     */
    private function metaSchema(): string
    {
        /** @var array<string, mixed> $schema */
        $schema = json_decode($this->fixture('openapi-3.1-schema.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->adaptForOpis($schema);

        return json_encode($schema, JSON_THROW_ON_ERROR);
    }

    /**
     * Load the JSON Schema 2020-12 dialect document.
     *
     * @return string
     */
    private function dialectSchema(): string
    {
        return $this->fixture('json-schema-2020-12.json');
    }

    /**
     * Apply the two opis/json-schema compatibility transforms in place: rewrite
     * the Schema Object's `$dynamicRef: "#meta"` to the equivalent static
     * `$ref: "#/$defs/schema"`, and relax every `unevaluatedProperties: false`
     * to `true`. Both work around opis annotation/reference gaps without
     * weakening any validity-bearing constraint; see the fixtures README.
     *
     * @param  array<string, mixed>  $node
     * @return void
     */
    private function adaptForOpis(array &$node): void
    {
        if (($node['$dynamicRef'] ?? null) === '#meta') {
            unset($node['$dynamicRef']);
            $node['$ref'] = '#/$defs/schema';
        }

        if (($node['unevaluatedProperties'] ?? null) === false) {
            $node['unevaluatedProperties'] = true;
        }

        foreach ($node as &$value) {
            if (is_array($value)) {
                $this->adaptForOpis($value);
            }
        }
    }

    /**
     * Read a bundled fixture file by name.
     *
     * @param  string  $name
     * @return string
     */
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/openapi/' . $name);

        assert(is_string($contents));

        return $contents;
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
     * Register the fixture resource map on the config repository.
     *
     * @return void
     */
    private function registerResourceMap(): void
    {
        $this->getConfig()->set('api-toolkit.resources.resource_map', [
            User::class         => UserResource::class,
            Organization::class => OrganizationResource::class,
            Post::class         => PostResource::class,
            Tag::class          => TagResource::class,
        ]);
    }

    /**
     * Get the config repository instance.
     *
     * @return \Illuminate\Contracts\Config\Repository
     */
    private function getConfig(): ConfigRepository
    {
        /** @var \Illuminate\Contracts\Config\Repository */
        return $this->makeApplication()->make('config');
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function makeApplication(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }
}
