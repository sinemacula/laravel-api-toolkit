<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Examples;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleComposer;
use SineMacula\ApiToolkit\OpenApi\Examples\ExampleComposition;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleGenerationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleMismatchException;

/**
 * Tests for the example composer.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ExampleComposer::class)]
#[CoversClass(ExampleComposition::class)]
final class ExampleComposerTest extends TestCase
{
    /**
     * Test that every JSON request body and success body gets an example, while
     * error, multipart and already exemplified bodies keep theirs.
     *
     * @return void
     */
    public function testRequestAndSuccessBodiesGetAnExample(): void
    {
        $object  = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];
        $error   = ['schema' => $object];
        $upload  = ['schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]]];
        $present = ['schema' => $object, 'examples' => ['given' => ['value' => ['name' => 'Ada']]]];

        $composition = (new ExampleComposer)->compose(['paths' => ['/users' => [
            'put' => [
                'requestBody' => ['content' => ['multipart/form-data' => $upload]],
                'responses'   => [200 => ['content' => ['application/json' => $present]]],
            ],
            'post' => [
                'requestBody' => ['content' => ['application/json' => ['schema' => $object]]],
                'responses'   => [201 => ['content' => ['application/json' => ['schema' => $object]]], 422 => ['content' => ['application/json' => $error]]],
            ],
        ]]], 'public');

        $operations = $composition->document['paths']['/users'];
        $generated  = '{"default":{"value":{"name":"string"}}}';

        self::assertSame($generated, json_encode($operations['post']['requestBody']['content']['application/json']['examples'], JSON_THROW_ON_ERROR));
        self::assertSame($generated, json_encode($operations['post']['responses'][201]['content']['application/json']['examples'], JSON_THROW_ON_ERROR));
        self::assertSame($object, $operations['post']['responses'][201]['content']['application/json']['schema']);
        self::assertSame($error, $operations['post']['responses'][422]['content']['application/json']);
        self::assertSame($upload, $operations['put']['requestBody']['content']['multipart/form-data']);
        self::assertSame($present, $operations['put']['responses'][200]['content']['application/json']);
        self::assertSame([], $composition->skipped);
    }

    /**
     * Test that a body needing an undocumented field is reported, while the
     * envelope of a wholly undocumented body is left quietly.
     *
     * @return void
     */
    public function testUndocumentedFieldIsReportedUnlessTheWholePayloadIs(): void
    {
        $data   = ['type' => 'object', 'properties' => ['meta' => ['x-undocumented' => true]], 'required' => ['meta']];
        $partly = ['type' => 'object', 'properties' => ['data' => $data], 'required' => ['data']];
        $wholly = ['type' => 'object', 'properties' => ['data' => ['x-undocumented' => true]], 'required' => ['data']];

        $beside = ['type' => 'object', 'properties' => ['data' => ['x-undocumented' => true], 'id' => ['type' => 'integer']], 'required' => ['data']];

        $composition = (new ExampleComposer)->compose(['paths' => [
            '/partly' => ['get' => ['responses' => [200 => ['content' => ['application/json' => ['schema' => $partly]]]]]],
            '/wholly' => ['get' => ['responses' => [200 => ['content' => ['application/json' => ['schema' => $wholly]]]]]],
            '/beside' => ['post' => ['requestBody' => ['content' => ['application/json' => ['schema' => $beside]]]]],
            '/fully'  => ['get' => ['responses' => [200 => ['content' => ['application/json' => ['schema' => ['type' => 'boolean']]]]]]],
        ]], 'public');

        self::assertSame(
            [
                'No example for GET /partly response 200 in the [public] audience: the value at'
                . ' #/paths/~1partly/get/responses/200/content/application~1json/schema/properties/data/properties/meta is undocumented.',
                'No example for POST /beside request body in the [public] audience: the value at'
                . ' #/paths/~1beside/post/requestBody/content/application~1json/schema/properties/data is undocumented.',
            ],
            $composition->skipped,
        );
        self::assertArrayNotHasKey('examples', (array) $composition->document['paths']['/partly']['get']['responses'][200]['content']['application/json']);
        self::assertArrayNotHasKey('examples', (array) $composition->document['paths']['/wholly']['get']['responses'][200]['content']['application/json']);
        self::assertSame(['default' => ['value' => true]], $composition->document['paths']['/fully']['get']['responses'][200]['content']['application/json']['examples']);
    }

    /**
     * Test that a required request field the generator finds no value for
     * fails, naming its pointer and the request remedy.
     *
     * @return void
     */
    public function testUnsatisfiedRequestFieldFails(): void
    {
        $this->expectException(ExampleGenerationException::class);
        $this->expectExceptionMessage(
            'The exporter could not generate an example for POST /members request body in the [public] audience: none of its values satisfies the schema at'
            . ' #/paths/~1members/post/requestBody/content/application~1json/schema/properties/code. Give the field a value with fieldExamples() on its rules source.',
        );

        (new ExampleComposer)->compose(['paths' => ['/members' => ['post' => ['requestBody' => ['content' => ['application/json' => [
            'schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$']], 'required' => ['code']],
        ]]]]]]], 'public');
    }

    /**
     * Test that a required response field the generator finds no value for
     * fails, naming its pointer and the response remedy.
     *
     * @return void
     */
    public function testUnsatisfiedResponseFieldFails(): void
    {
        $this->expectException(ExampleGenerationException::class);
        $this->expectExceptionMessage(
            'The exporter could not generate an example for GET /codes response 200 in the [internal] audience: none of its values satisfies the schema at'
            . ' #/components/schemas/Code/properties/code. Give the field a value with ->openapi()->example(), or an examples keyword in its DefinesSchema.',
        );

        (new ExampleComposer)->compose([
            'paths'      => ['/codes' => ['get' => ['responses' => [200 => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Code']]]]]]]],
            'components' => ['schemas' => ['Code' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$']], 'required' => ['code']]]],
        ], 'internal');
    }

    /**
     * Test that a generated example its schema rejects fails, as an ambiguous
     * union does.
     *
     * @return void
     */
    public function testGeneratedExampleItsSchemaRejectsFails(): void
    {
        $this->expectException(ExampleMismatchException::class);
        $this->expectExceptionMessage(
            'The generated example [Alternative 1] for GET /shapes response 200 in the [public] audience does not match its schema'
            . ' at /data: The data should match exactly one schema',
        );

        (new ExampleComposer)->compose(['paths' => ['/shapes' => ['get' => ['responses' => [200 => ['content' => ['application/json' => ['schema' => [
            'type'       => 'object',
            'properties' => ['data' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
                ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 0]], 'required' => ['id']],
            ]]],
            'required' => ['data'],
        ]]]]]]]]], 'public');
    }

    /**
     * Test that the examples a document already carries are validated before
     * any is generated.
     *
     * @return void
     */
    public function testCarriedExamplesAreValidatedFirst(): void
    {
        $this->expectException(ExampleMismatchException::class);
        $this->expectExceptionMessage('The schema example [0] for #/paths/~1members/post/requestBody/content/application~1json/schema/properties/code in the [public] audience');

        (new ExampleComposer)->compose(['paths' => ['/members' => ['post' => ['requestBody' => ['content' => ['application/json' => [
            'schema' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'examples' => ['abc']]], 'required' => ['code']],
        ]]]]]]], 'public');
    }
}
