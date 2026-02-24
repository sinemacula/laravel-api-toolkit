<?php

namespace Tests\Unit\Http\Controllers;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Controllers\RespondsWithStream;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Exporter\Facades\Exporter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\Fixtures\Support\FunctionOverrides;
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
    /** @var string */
    private const string TEST_URI = '/test';

    /**
     * Test that streamRepositoryToCsv returns a StreamedResponse.
     *
     * @return void
     */
    public function testStreamRepositoryToCsvReturnsStreamedResponse(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, 'GET');
        ApiQuery::parse($request);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass') // @phpstan-ignore method.notFound
            ->andReturn(\Tests\Fixtures\Resources\UserResource::class);
        $repository->shouldReceive('chunkById') // @phpstan-ignore method.notFound
            ->andReturn(true);

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

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

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass')->once()->andReturn(null); // @phpstan-ignore method.notFound

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

        /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
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

        /** @var \Mockery\MockInterface $chain_mock */
        $chain_mock = \Mockery::mock();
        $chain_mock->shouldReceive('withoutFields')->andReturnSelf(); // @phpstan-ignore method.notFound
        $chain_mock->shouldReceive('exportArray')->once()->andReturn("name,email\nJohn,john@example.com\n"); // @phpstan-ignore method.notFound

        /** @var \Mockery\MockInterface $facade_mock */
        $facade_mock = \Mockery::mock();
        $facade_mock->shouldReceive('format')->with('csv')->once()->andReturn($chain_mock); // @phpstan-ignore method.notFound

        Exporter::swap($facade_mock);

        $reflection = new \ReflectionMethod($controller, 'formatChunkAsCsv');

        $is_first = true;
        $args     = [[['name' => 'John', 'email' => 'john@example.com']], &$is_first];

        /** @var string $result */
        $result = $reflection->invokeArgs($controller, $args);

        static::assertStringContainsString('name', $result);
        // @phpstan-ignore staticMethod.impossibleType
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

        /** @var \Mockery\MockInterface $chain_mock */
        $chain_mock = \Mockery::mock();
        $chain_mock->shouldReceive('withoutFields')->andReturnSelf(); // @phpstan-ignore method.notFound
        $chain_mock->shouldReceive('withoutHeaders')->once()->andReturnSelf(); // @phpstan-ignore method.notFound
        $chain_mock->shouldReceive('exportArray')->once()->andReturn("Jane,jane@example.com\n"); // @phpstan-ignore method.notFound

        /** @var \Mockery\MockInterface $facade_mock */
        $facade_mock = \Mockery::mock();
        $facade_mock->shouldReceive('format')->with('csv')->once()->andReturn($chain_mock); // @phpstan-ignore method.notFound

        Exporter::swap($facade_mock);

        $reflection = new \ReflectionMethod($controller, 'formatChunkAsCsv');

        $is_first = false;
        $args     = [[['name' => 'Jane', 'email' => 'jane@example.com']], &$is_first];

        /** @var string $result */
        $result = $reflection->invokeArgs($controller, $args);

        static::assertStringContainsString('Jane', $result);
    }

    /**
     * Test that the chunk callback is invoked and outputs CSV data.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @return void
     */
    public function testStreamRepositoryToCsvInvokesChunkCallback(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, 'GET');
        ApiQuery::parse($request);

        $user = User::create(['name' => 'Streamed', 'email' => 'streamed@example.com']);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass') // @phpstan-ignore method.notFound
            ->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById') // @phpstan-ignore method.notFound
            ->andReturnUsing(function (int $_size, callable $callback) use ($user): bool {
                $callback(collect([$user]));

                return true;
            });

        /** @var \Mockery\MockInterface $chain */
        $chain = \Mockery::mock();
        $chain->shouldReceive('withoutFields')->andReturnSelf(); // @phpstan-ignore method.notFound
        $chain->shouldReceive('withoutHeaders')->andReturnSelf(); // @phpstan-ignore method.notFound
        $chain->shouldReceive('exportArray')->andReturn("id,name\n1,Streamed\n"); // @phpstan-ignore method.notFound

        /** @var \Mockery\MockInterface $exporter */
        $exporter = \Mockery::mock();
        $exporter->shouldReceive('format')->andReturn($chain); // @phpstan-ignore method.notFound

        Exporter::swap($exporter);

        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        ob_start();
        $response->sendContent();
        ob_end_clean();

        static::assertInstanceOf(StreamedResponse::class, $response);
    }

    /**
     * Test that the limit cap stops chunking once processed count reaches the
     * limit.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @return void
     */
    public function testStreamRepositoryToCsvRespectsLimit(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, 'GET', ['limit' => '1']);
        ApiQuery::parse($request);

        $user1 = User::create(['name' => 'User1', 'email' => 'user1@stream.com']);
        $user2 = User::create(['name' => 'User2', 'email' => 'user2@stream.com']);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass') // @phpstan-ignore method.notFound
            ->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById') // @phpstan-ignore method.notFound
            ->andReturnUsing(function (int $_size, callable $callback) use ($user1, $user2): bool {
                $callback(collect([$user1]));
                $callback(collect([$user2]));

                return true;
            });

        /** @var \Mockery\MockInterface $chain */
        $chain = \Mockery::mock();
        $chain->shouldReceive('withoutFields')->andReturnSelf(); // @phpstan-ignore method.notFound
        $chain->shouldReceive('withoutHeaders')->andReturnSelf(); // @phpstan-ignore method.notFound
        $chain->shouldReceive('exportArray')->andReturn("id,name\n1,User1\n"); // @phpstan-ignore method.notFound

        /** @var \Mockery\MockInterface $exporter */
        $exporter = \Mockery::mock();
        $exporter->shouldReceive('format')->andReturn($chain); // @phpstan-ignore method.notFound

        Exporter::swap($exporter);

        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        ob_start();
        $response->sendContent();
        ob_end_clean();

        static::assertInstanceOf(StreamedResponse::class, $response);
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
