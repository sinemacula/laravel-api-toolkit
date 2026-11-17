<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ValidatesOpenApiDocuments;

/**
 * Negative controls for the shared OpenAPI validation harness.
 *
 * A harness that accepted one of these would pass every validity assertion on
 * an export carrying the same defect.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversNothing]
final class OpenApiValidationHarnessTest extends TestCase
{
    use ValidatesOpenApiDocuments;

    /** @var string The key of the path item in the well-formed document. */
    private const string PATH = 'paths./widgets/{widget}';

    /**
     * Test that the well-formed document validates.
     *
     * @return void
     */
    public function testWellFormedDocumentValidates(): void
    {
        $this->assertValidDocument(self::document());
    }

    /**
     * Provide a malformed value and the key it is set at in the well-formed
     * document.
     *
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function malformedDocumentProvider(): iterable
    {
        yield 'a 3.0 version string' => ['openapi', '3.0.3'];
        yield 'an info object without a version' => ['info', ['title' => 'Widgets']];
        yield 'a misspelt info field' => ['info.licence', ['name' => 'MIT']];
        yield 'a misspelt components field' => ['components.securitySchemas', []];
        yield 'a query operation' => [self::PATH . '.query', ['responses' => ['200' => ['description' => 'OK']]]];
        yield 'a misspelt operation id' => [self::PATH . '.get.operationID', 'showWidget'];
        yield 'a misspelt response content' => [self::PATH . '.get.responses.200.contnet', ['application/json' => ['schema' => ['type' => 'string']]]];
        yield 'a misspelt media type field' => [self::PATH . '.get.responses.200.content.application/json.schmea', ['type' => 'string']];
        yield 'a path without a leading slash' => ['paths.widgets', ['summary' => 'Widgets']];
        yield 'a misspelt parameter field' => ['components.parameters.Include.requird', true];
        yield 'a query parameter with a path style' => ['components.parameters.Include.style', 'matrix'];
        yield 'a parameter without a name or location' => ['components.parameters.Broken', ['schema' => ['type' => 'string']]];
        yield 'a misspelt header field' => ['components.headers.Total-Count.descripton', 'Total'];
        yield 'a schema encoded as a list' => ['components.schemas.Widget.properties.name', []];
        yield 'a properties map encoded as a list' => ['components.schemas.Widget.properties', [['type' => 'string']]];
        yield 'an unknown schema type' => ['components.schemas.Widget.properties.name.type', 'nullable'];
        yield 'a non-string schema format' => ['components.schemas.Widget.properties.name.format', 5];
        yield 'a templated server url that is not a uri-reference' => ['servers.0.url', 'https://{region}.api.example.com/a path'];
    }

    /**
     * Test that each malformed document is rejected.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    #[DataProvider('malformedDocumentProvider')]
    public function testMalformedDocumentIsRejected(string $key, mixed $value): void
    {
        $document = self::document();

        Arr::set($document, $key, $value);

        self::assertFalse($this->validateDocument($document)->isValid());
    }

    /**
     * Test that a raw JSON document is held to the same meta-schema.
     *
     * @return void
     */
    public function testRawJsonIsHeldToTheMetaSchema(): void
    {
        $json = json_encode(self::document(), JSON_THROW_ON_ERROR);

        self::assertTrue($this->validateJson($json)->isValid());
        self::assertFalse($this->validateJson(str_replace('"operationId"', '"operationID"', $json))->isValid());
    }

    /**
     * Build a well-formed document exercising every object the mutations
     * target.
     *
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info'    => ['title' => 'Widgets', 'version' => '1.0.0'],
            'servers' => [['url' => 'https://{region}.api.example.com', 'variables' => ['region' => ['default' => 'eu']]]],
            'paths'   => [
                '/widgets/{widget}' => [
                    'parameters' => [['name' => 'widget', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'get'        => [
                        'operationId' => 'showWidget',
                        'parameters'  => [['$ref' => '#/components/parameters/Include']],
                        'responses'   => [
                            '200' => [
                                'description' => 'OK',
                                'headers'     => ['Total-Count' => ['$ref' => '#/components/headers/Total-Count']],
                                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Widget']]],
                            ],
                        ],
                    ],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Widget' => ['type' => 'object', 'properties' => ['name' => ['type' => ['string', 'null'], 'format' => 'email']]],
                ],
                'parameters' => [
                    'Include' => ['name' => 'include', 'in' => 'query', 'style' => 'form', 'explode' => false, 'schema' => ['type' => 'string']],
                ],
                'headers' => [
                    'Total-Count' => ['description' => 'The total count', 'schema' => ['type' => 'integer']],
                ],
            ],
        ];
    }
}
