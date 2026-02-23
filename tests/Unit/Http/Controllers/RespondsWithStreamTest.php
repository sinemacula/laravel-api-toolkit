<?php

namespace Tests\Unit\Http\Controllers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Controllers\RespondsWithStream;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Exporter\Facades\Exporter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Tests for the RespondsWithStream trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RespondsWithStream::class)]
class RespondsWithStreamTest extends TestCase
{
    /**
     * Test that streamRepositoryToCsv returns a StreamedResponse.
     *
     * @return void
     */
    public function testStreamRepositoryToCsvReturnsStreamedResponse(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create('/test', 'GET');
        ApiQuery::parse($request);

        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass')
            ->andReturn(\Tests\Fixtures\Resources\UserResource::class);
        $repository->shouldReceive('chunkById')
            ->andReturn(true);

        $response = $controller->streamRepositoryToCsv($repository);

        static::assertInstanceOf(StreamedResponse::class, $response);
    }

    /**
     * Test that makeTransformer throws InvalidArgumentException when no resource class.
     *
     * @return void
     */
    public function testMakeTransformerThrowsWhenNoResourceClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to resolve resource class from repository.');

        $controller = $this->createControllerWithTrait();

        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass')
            ->once()
            ->andReturn(null);

        $reflection = new \ReflectionMethod($controller, 'makeTransformer');
        $reflection->invoke($controller, $repository);
    }

    /**
     * Test that createStreamedResponse sets correct content type.
     *
     * @return void
     */
    public function testCreateStreamedResponseSetsCorrectContentType(): void
    {
        $controller = $this->createControllerWithTrait();

        $reflection = new \ReflectionMethod($controller, 'createStreamedResponse');

        $response = $reflection->invoke($controller, function (): void {
            echo 'test';
        }, 'text/csv', 'export.csv');

        static::assertInstanceOf(StreamedResponse::class, $response);
        static::assertSame('text/csv', $response->headers->get('Content-Type'));
    }

    /**
     * Test that formatChunkAsCsv includes headers on first chunk.
     *
     * @return void
     */
    public function testFormatChunkAsCsvIncludesHeadersOnFirstChunk(): void
    {
        $controller = $this->createControllerWithTrait();

        $chain_mock = \Mockery::mock();
        $chain_mock->shouldReceive('withoutFields')->andReturnSelf();
        $chain_mock->shouldReceive('exportArray')->once()->andReturn("name,email\nJohn,john@example.com\n");

        $facade_mock = \Mockery::mock();
        $facade_mock->shouldReceive('format')
            ->with('csv')
            ->once()
            ->andReturn($chain_mock);

        Exporter::swap($facade_mock);

        $reflection = new \ReflectionMethod($controller, 'formatChunkAsCsv');

        $is_first = true;
        $args     = [[['name' => 'John', 'email' => 'john@example.com']], &$is_first];
        $result   = $reflection->invokeArgs($controller, $args);

        static::assertStringContainsString('name', $result);
        static::assertFalse($is_first);
    }

    /**
     * Test that formatChunkAsCsv excludes headers on subsequent chunks.
     *
     * @return void
     */
    public function testFormatChunkAsCsvExcludesHeadersOnSubsequentChunks(): void
    {
        $controller = $this->createControllerWithTrait();

        $chain_mock = \Mockery::mock();
        $chain_mock->shouldReceive('withoutFields')->andReturnSelf();
        $chain_mock->shouldReceive('withoutHeaders')->once()->andReturnSelf();
        $chain_mock->shouldReceive('exportArray')->once()->andReturn("Jane,jane@example.com\n");

        $facade_mock = \Mockery::mock();
        $facade_mock->shouldReceive('format')
            ->with('csv')
            ->once()
            ->andReturn($chain_mock);

        Exporter::swap($facade_mock);

        $reflection = new \ReflectionMethod($controller, 'formatChunkAsCsv');

        $is_first = false;
        $args     = [[['name' => 'Jane', 'email' => 'jane@example.com']], &$is_first];
        $result   = $reflection->invokeArgs($controller, $args);

        static::assertStringContainsString('Jane', $result);
    }

    /**
     * Create a controller instance that uses the RespondsWithStream trait.
     *
     * @return object
     */
    private function createControllerWithTrait(): object
    {
        return new class {
            use RespondsWithStream;
        };
    }
}
