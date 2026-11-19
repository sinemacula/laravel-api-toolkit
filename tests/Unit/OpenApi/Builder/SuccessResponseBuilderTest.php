<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ExampleDeclarationException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver;
use SineMacula\ApiToolkit\OpenApi\Schema\DefinedSchemaRegistry;
use Tests\Concerns\WritesDocsDirectories;
use Tests\Fixtures\OpenApi\PathHeaderCollisionController;
use Tests\Fixtures\OpenApi\PathResponseExampleController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PathResponseStatusController;
use Tests\Fixtures\OpenApi\PathStatusConflictController;
use Tests\Fixtures\OpenApi\PathStatusUserController;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the success response builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SuccessResponseBuilder::class)]
final class SuccessResponseBuilderTest extends TestCase
{
    use WritesDocsDirectories;

    /** The header every action of the status fixtures declares */
    private const array REQUEST_ID_HEADER = ['X-Request-Id' => ['schema' => ['type' => 'string']]];

    /** The total-count header the exporter emits on a collection */
    private const array TOTAL_COUNT_HEADER = ['Total-Count' => ['$ref' => '#/components/headers/Total-Count']];

    /** The reference to the widget shape's component */
    private const string WIDGET_REF = '#/components/schemas/WidgetShape';

    /** A body standing in for the one the exporter derives */
    private const array CONTENT = ['application/json' => ['schema' => ['type' => 'object']]];

    /** An operation answering with that body */
    private const array OPERATION = ['responses' => [200 => ['description' => 'Done.', 'content' => self::CONTENT]]];

    /**
     * Test that the body is the shape the action's response schema declares.
     *
     * @return void
     */
    public function testBodyIsTheDeclaredSchema(): void
    {
        self::assertSame(
            ['content' => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelope(self::WIDGET_REF)]]],
            $this->builder()->body(PathResponseStatusController::class, 'queue'),
        );
    }

    /**
     * Test that a declared collection's body documents the total-count header
     * its length-aware page carries.
     *
     * @return void
     */
    public function testCollectionBodyCarriesTheTotalCountHeader(): void
    {
        self::assertSame(
            [
                'headers' => self::TOTAL_COUNT_HEADER,
                'content' => ['application/json' => ['schema' => (new EnvelopeBuilder)->anyCollectionEnvelopeFor(['$ref' => self::WIDGET_REF])]],
            ],
            $this->builder()->body(PathResponseSchemaController::class, 'widgets'),
        );
    }

    /**
     * Test that the body falls back to the undocumented envelope.
     *
     * @return void
     */
    public function testBodyFallsBackToTheUndocumentedEnvelope(): void
    {
        self::assertSame(
            ['content' => ['application/json' => ['schema' => (new EnvelopeBuilder)->undocumentedEnvelope()]]],
            $this->builder()->body(PathResponseStatusController::class, 'bare'),
        );
    }

    /**
     * Test that a response schema replaces the resource only when it differs.
     *
     * @return void
     */
    public function testReplacesResourceFollowsTheResponseSchema(): void
    {
        $builder = $this->builder();

        self::assertTrue($builder->replacesResource(PathResponseSchemaController::class, 'widget', UserResource::class, false));
        self::assertTrue($builder->replacesResource(PathResponseSchemaController::class, 'list', UserResource::class, false));
        self::assertFalse($builder->replacesResource(PathResponseSchemaController::class, 'list', UserResource::class, true));
        self::assertFalse($builder->replacesResource(PathResponseSchemaController::class, 'bare', UserResource::class, false));
    }

    /**
     * Test that a response is untouched without a declared status or header.
     *
     * @return void
     */
    public function testAnUndeclaredResponseIsUntouched(): void
    {
        $responses = ['200' => ['description' => 'Done.', 'content' => self::CONTENT]];

        self::assertSame($responses, $this->builder()->declare($responses, PathResponseHeaderController::class, 'bare'));
        self::assertSame($responses, $this->builder()->declare($responses, null, \Closure::class));
    }

    /**
     * Test that declaring the default status only adds the declared headers.
     *
     * @return void
     */
    public function testDeclaringTheDefaultStatusKeepsTheResponse(): void
    {
        self::assertSame(
            [200 => ['description' => 'Done.', 'headers' => self::REQUEST_ID_HEADER, 'content' => self::CONTENT]],
            $this->builder()->declare(['200' => ['description' => 'Done.', 'content' => self::CONTENT]], PathStatusUserController::class, 'show'),
        );
    }

    /**
     * Test that a no-content status drops the body and exporter headers.
     *
     * @return void
     */
    public function testANoContentStatusDropsTheBodyAndExporterHeaders(): void
    {
        $responses = ['200' => ['description' => 'Done.', 'headers' => self::TOTAL_COUNT_HEADER, 'content' => self::CONTENT]];

        self::assertSame(
            [204 => ['description' => 'No Content.', 'headers' => self::REQUEST_ID_HEADER]],
            $this->builder()->declare($responses, PathStatusUserController::class, 'update'),
        );
    }

    /**
     * Test that another status keeps the body, then the declared headers.
     *
     * @return void
     */
    public function testAnyOtherStatusKeepsTheResponse(): void
    {
        $responses = ['200' => ['description' => 'Done.', 'headers' => self::TOTAL_COUNT_HEADER, 'content' => self::CONTENT]];

        self::assertSame(
            [206 => ['description' => 'Partial Content.', 'headers' => [...self::TOTAL_COUNT_HEADER, ...self::REQUEST_ID_HEADER], 'content' => self::CONTENT]],
            $this->builder()->declare($responses, PathStatusUserController::class, 'index'),
        );
    }

    /**
     * Test that a body-carrying status gives a bodyless response its body.
     *
     * @return void
     */
    public function testABodyStatusGivesABodylessResponseItsBody(): void
    {
        self::assertSame(
            [200 => [
                'description' => 'OK.',
                'headers'     => self::REQUEST_ID_HEADER,
                'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelope(self::WIDGET_REF)]],
            ]],
            $this->builder()->declare(['204' => ['description' => 'Deleted.']], PathResponseStatusController::class, 'destroy'),
        );
    }

    /**
     * Test that redeclaring a header the exporter emits, under any case, fails.
     *
     * @return void
     */
    public function testRedeclaringAnExporterHeaderFails(): void
    {
        $this->expectException(ResponseHeaderCollisionException::class);
        $this->expectExceptionMessage(sprintf(
            'The TOTAL-COUNT header declared for %s::index is emitted by the exporter and cannot be redeclared',
            PathHeaderCollisionController::class,
        ));

        $this->builder()->declare(['200' => ['description' => 'Done.', 'headers' => self::TOTAL_COUNT_HEADER]], PathHeaderCollisionController::class, 'index');
    }

    /**
     * Test that a body declared beside a no-content status fails.
     *
     * @return void
     */
    public function testABodyBesideANoContentStatusFails(): void
    {
        $this->expectException(ResponseStatusConflictException::class);

        $this->builder()->declare(['200' => ['description' => 'Done.']], PathStatusConflictController::class, 'update');
    }

    /**
     * Test that the declared examples are read for the audience and attached to
     * the success body in declaration order, the operation otherwise kept.
     *
     * @return void
     */
    public function testDeclaredExamplesAttachToTheSuccessBodyInOrder(): void
    {
        $this->useDocsDirectory([
            'examples/auth/signed-in.json'                    => '{"data": {"token": "shared", "meta": {}}}',
            'examples/auth/remembered.json'                   => '{"data": {"token": "remembered"}}',
            'audiences/internal/examples/auth/signed-in.json' => '{"data": {"token": "internal"}}',
        ]);

        $operation = ['responses' => self::OPERATION['responses'] + [401 => ['description' => 'Unauthenticated.']], 'security' => []];
        $internal  = $this->builder()->withExamples($operation, PathResponseExampleController::class, 'signIn', 'internal');
        $public    = $this->builder()->withExamples($operation, PathResponseExampleController::class, 'signIn', 'public');

        self::assertSame(['responses', 'security'], array_keys($internal));
        self::assertSame($operation['responses'][401], $internal['responses'][401]);
        self::assertSame(
            '{"signed-in":{"value":{"data":{"token":"internal"}}},"remembered":{"value":{"data":{"token":"remembered"}}}}',
            json_encode($this->examplesOf($internal, 200), JSON_THROW_ON_ERROR),
        );
        self::assertSame('{"value":{"data":{"token":"shared","meta":{}}}}', json_encode($this->examplesOf($public, 200)['signed-in'], JSON_THROW_ON_ERROR));
    }

    /**
     * Test that an example attaches under the status the action declares.
     *
     * @return void
     */
    public function testExampleAttachesUnderTheDeclaredStatus(): void
    {
        $this->useDocsDirectory(['examples/auth/signed-in.json' => '{"data": {}}']);

        $operation = ['responses' => [202 => ['description' => 'Accepted.', 'content' => self::CONTENT]]];

        self::assertSame(['queued'], array_keys($this->examplesOf($this->builder()->withExamples($operation, PathResponseExampleController::class, 'queue', 'public'), 202)));
    }

    /**
     * Test that an action declaring no example keeps its operation.
     *
     * @return void
     */
    public function testUndeclaredActionKeepsItsOperation(): void
    {
        $operation = [...self::OPERATION, 'security' => []];

        self::assertSame($operation, $this->builder()->withExamples($operation, PathResponseExampleController::class, 'bare', 'public'));
        self::assertSame($operation, $this->builder()->withExamples($operation, null, 'bare', 'public'));
    }

    /**
     * Test that an example declared on a body that is not a DefinesSchema shape
     * fails.
     *
     * @return void
     */
    public function testExampleOnABodyThatIsNotAShapeFails(): void
    {
        $this->expectDeclarationFailure(sprintf(
            'The response example [anything] on %s::undocumented cannot be declared: the response body is not a DefinesSchema shape.'
            . ' A resource body\'s examples are generated, so give its fields example values instead.',
            PathResponseExampleController::class,
        ));

        $this->builder()->withExamples(self::OPERATION, PathResponseExampleController::class, 'undocumented', 'public');
    }

    /**
     * Test that an example naming a status the action does not answer with
     * fails.
     *
     * @return void
     */
    public function testExampleNamingAnotherStatusFails(): void
    {
        $this->expectDeclarationFailure(sprintf(
            'The response example [created] on %s::misdirected names status 201, but the action answers with 200.',
            PathResponseExampleController::class,
        ));

        $this->builder()->withExamples(self::OPERATION, PathResponseExampleController::class, 'misdirected', 'public');
    }

    /**
     * Test that an example naming a status without a body fails.
     *
     * @return void
     */
    public function testExampleNamingABodylessStatusFails(): void
    {
        $this->expectDeclarationFailure(sprintf('The response example [deleted] on %s::destroy names status 204, which has no body.', PathResponseExampleController::class));

        $operation = ['responses' => [204 => ['description' => 'Deleted.'], 404 => ['description' => 'Missing.', 'content' => self::CONTENT]]];

        $this->builder()->withExamples($operation, PathResponseExampleController::class, 'destroy', 'public');
    }

    /**
     * Read the examples of a built operation's body under the status.
     *
     * @param  array<string, mixed>  $operation
     * @param  int  $status
     * @return array<array-key, mixed>
     */
    private function examplesOf(array $operation, int $status): array
    {
        $examples = $operation['responses'][$status]['content']['application/json']['examples'] ?? null;

        self::assertIsArray($examples);

        return $examples;
    }

    /**
     * Expect an example declaration to fail with the message.
     *
     * @param  string  $message
     * @return void
     */
    private function expectDeclarationFailure(string $message): void
    {
        $this->expectException(ExampleDeclarationException::class);
        $this->expectExceptionMessage($message);
    }

    /**
     * Build a success response builder over a catalogue registering nothing.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder
     */
    private function builder(): SuccessResponseBuilder
    {
        $catalogue = self::createStub(MetadataCatalogue::class);
        $catalogue->method('getResourceMap')->willReturn([]);

        return new SuccessResponseBuilder(
            new EnvelopeBuilder,
            new ResponseSchemaResolver($catalogue, new EnvelopeBuilder, new DefinedSchemaRegistry),
            new ResponseHeaderResolver,
            new ResponseStatusResolver,
        );
    }
}
