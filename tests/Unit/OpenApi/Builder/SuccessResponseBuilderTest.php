<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Builder\EnvelopeBuilder;
use SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\Contracts\MetadataCatalogue;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseHeaderCollisionException;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ResponseStatusConflictException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseHeaderResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseSchemaResolver;
use SineMacula\ApiToolkit\OpenApi\Resolution\ResponseStatusResolver;
use Tests\Fixtures\OpenApi\PathHeaderCollisionController;
use Tests\Fixtures\OpenApi\PathResponseHeaderController;
use Tests\Fixtures\OpenApi\PathResponseSchemaController;
use Tests\Fixtures\OpenApi\PathResponseStatusController;
use Tests\Fixtures\OpenApi\PathStatusConflictController;
use Tests\Fixtures\OpenApi\PathStatusUserController;
use Tests\Fixtures\OpenApi\WidgetShape;
use Tests\Fixtures\Resources\UserResource;

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
    /** The header every action of the status fixtures declares */
    private const array REQUEST_ID_HEADER = ['X-Request-Id' => ['schema' => ['type' => 'string']]];

    /** The total-count header the exporter emits on a collection */
    private const array TOTAL_COUNT_HEADER = ['Total-Count' => ['$ref' => '#/components/headers/Total-Count']];

    /** A body standing in for the one the exporter derives */
    private const array CONTENT = ['application/json' => ['schema' => ['type' => 'object']]];

    /**
     * Test that the body is the shape the action's response schema declares.
     *
     * @return void
     */
    public function testBodyIsTheDeclaredSchema(): void
    {
        self::assertSame(
            ['content' => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelopeFor(WidgetShape::schema())]]],
            $this->builder()->body(PathResponseStatusController::class, 'queue'),
        );
    }

    /**
     * Test that the body falls back to the undocumented envelope when the
     * action declares no response schema.
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
     * Test that an action's response schema replaces the resource exactly when
     * it names anything but that resource in the given shape.
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
     * Test that a response is left untouched when the action declares neither a
     * status nor a header, or is a closure.
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
     * Test that declaring the default status keeps the response, only adding
     * the declared headers between its description and its body.
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
     * Test that a no-content status drops the body and the headers the exporter
     * emits beside it, keeping the declared headers.
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
     * Test that any other status keeps the body and the exporter headers, the
     * declared headers following them.
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
     * Test that a status carrying a body gives a response that carried none the
     * action's declared body.
     *
     * @return void
     */
    public function testABodyStatusGivesABodylessResponseItsBody(): void
    {
        self::assertSame(
            [200 => [
                'description' => 'OK.',
                'headers'     => self::REQUEST_ID_HEADER,
                'content'     => ['application/json' => ['schema' => (new EnvelopeBuilder)->singleEnvelopeFor(WidgetShape::schema())]],
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
            new ResponseSchemaResolver($catalogue, new EnvelopeBuilder),
            new ResponseHeaderResolver,
            new ResponseStatusResolver,
        );
    }
}
