<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Concerns;

use Illuminate\Http\Request;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Facades\ApiQuery;
use SineMacula\ApiToolkit\Http\Concerns\RespondsWithStream;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Exporter\Facades\Exporter;
use SineMacula\Http\Enums\HttpMethod;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fixtures\Controllers\TestingExportController;
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
#[CoversTrait(RespondsWithStream::class)]
final class RespondsWithStreamTest extends TestCase
{
    /** @var string */
    private const string CONTENT_TYPE_CSV = 'text/csv; charset=utf-8';

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

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')
            ->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')
            ->andReturn(true);

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        static::assertInstanceOf(StreamedResponse::class, $response);
    }

    /**
     * Test that makeTransformer throws InvalidArgumentException when no
     * resource class.
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
        $repository->shouldReceive('getResourceClass')->once()->andReturn(null);

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
        $chain_mock->shouldReceive('withoutFields')->andReturnSelf();
        $chain_mock->shouldReceive('exportArray')->once()->andReturn("name,email\nJohn,john@example.com\n");

        /** @var \Mockery\MockInterface $facade_mock */
        $facade_mock = \Mockery::mock();
        $facade_mock->shouldReceive('format')->with('csv')->once()->andReturn($chain_mock);

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
        $chain_mock->shouldReceive('withoutFields')->andReturnSelf();
        $chain_mock->shouldReceive('withoutHeaders')->once()->andReturnSelf();
        $chain_mock->shouldReceive('exportArray')->once()->andReturn("Jane,jane@example.com\n");

        /** @var \Mockery\MockInterface $facade_mock */
        $facade_mock = \Mockery::mock();
        $facade_mock->shouldReceive('format')->with('csv')->once()->andReturn($chain_mock);

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

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        $user = User::create(['name' => 'Streamed', 'email' => 'streamed@example.com']);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')
            ->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')
            ->andReturnUsing(function (int $_size, callable $callback) use ($user): bool {
                $callback(collect([$user]));

                return true;
            });

        /** @var \Mockery\MockInterface $chain */
        $chain = \Mockery::mock();
        $chain->shouldReceive('withoutFields')->andReturnSelf();
        $chain->shouldReceive('withoutHeaders')->andReturnSelf();
        $chain->shouldReceive('exportArray')->andReturn("id,name\n1,Streamed\n");

        /** @var \Mockery\MockInterface $exporter */
        $exporter = \Mockery::mock();
        $exporter->shouldReceive('format')->andReturn($chain);

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

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['limit' => '1']);
        ApiQuery::parse($request);

        $user1 = User::create(['name' => 'User1', 'email' => 'user1@stream.com']);
        $user2 = User::create(['name' => 'User2', 'email' => 'user2@stream.com']);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')
            ->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')
            ->andReturnUsing(function (int $_size, callable $callback) use ($user1, $user2): bool {
                $callback(collect([$user1]));
                $callback(collect([$user2]));

                return true;
            });

        /** @var \Mockery\MockInterface $chain */
        $chain = \Mockery::mock();
        $chain->shouldReceive('withoutFields')->andReturnSelf();
        $chain->shouldReceive('withoutHeaders')->andReturnSelf();
        $chain->shouldReceive('exportArray')->andReturn("id,name\n1,User1\n");

        /** @var \Mockery\MockInterface $exporter */
        $exporter = \Mockery::mock();
        $exporter->shouldReceive('format')->andReturn($chain);

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
     * Test that streamRepositoryToCsv uses a custom filename in the
     * Content-Disposition header.
     *
     * @return void
     */
    public function testStreamRepositoryToCsvUsesCustomFilenameInContentDisposition(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')->andReturn(true);

        $response = $controller->streamRepositoryToCsv($repository, 1500, 'users.csv'); // @phpstan-ignore method.notFound

        static::assertStringContainsString('users.csv', $response->headers->get('Content-Disposition') ?? '');
    }

    /**
     * Test that streamRepositoryToCsv sets Content-Type with charset.
     *
     * @return void
     */
    public function testStreamRepositoryToCsvSetsContentTypeWithCharset(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')->andReturn(true);

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        static::assertSame(self::CONTENT_TYPE_CSV, $response->headers->get('Content-Type'));
    }

    /**
     * Test that streamRepositoryToCsv preserves trailing whitespace within CSV
     * field values while stripping trailing newlines between chunks.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @return void
     */
    public function testStreamRepositoryToCsvPreservesTrailingWhitespaceInCsvFieldValues(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        $user = User::create(['name' => 'Alice   ', 'email' => 'alice@example.com']);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')
            ->andReturnUsing(function (int $_size, callable $callback) use ($user): bool {
                $callback(collect([$user]));

                return true;
            });

        // CSV data where the last field value has trailing spaces followed by a
        // trailing newline. rtrim($csv, "\n") must strip only the newline.
        $csv = "id,name\n1,Alice   \n";

        /** @var \Mockery\MockInterface $chain */
        $chain = \Mockery::mock();
        $chain->shouldReceive('withoutFields')->andReturnSelf();
        $chain->shouldReceive('withoutHeaders')->andReturnSelf();
        $chain->shouldReceive('exportArray')->andReturn($csv);

        /** @var \Mockery\MockInterface $exporter */
        $exporter = \Mockery::mock();
        $exporter->shouldReceive('format')->andReturn($chain);

        Exporter::swap($exporter);

        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        // Trailing spaces within the field value must be preserved.
        static::assertStringContainsString('Alice   ', (string) $output);

        // Trailing newline must be stripped (chunk boundary prevention).
        static::assertStringEndsNotWith("\n", (string) $output);
    }

    /**
     * Test that streamRepositoryToCsv chunks the repository with the default
     * chunk size of fifteen hundred records.
     *
     * @return void
     */
    public function testStreamRepositoryToCsvUsesDefaultChunkSize(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        $captured_size = null;

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')
            ->andReturnUsing(function (int $size) use (&$captured_size): bool {
                $captured_size = $size;

                return true;
            });

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        ob_start();
        $response->sendContent();
        ob_end_clean();

        static::assertSame(1500, $captured_size);
    }

    /**
     * Test that streamRepositoryToCsv strips user-defined ordering by adding
     * a scope that reorders the query before chunking begins.
     *
     * @return void
     */
    public function testStreamRepositoryToCsvStripsUserOrderingBeforeChunking(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        $reordered = false;

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);
        $repository->shouldReceive('addScope')
            ->once()
            ->andReturnUsing(function (\Closure $scope) use (&$reordered, &$repository): ApiRepository {

                /** @var \Mockery\MockInterface $query */
                $query = \Mockery::mock();
                $query->shouldReceive('getQuery')->once()->andReturnSelf();
                $query->shouldReceive('reorder')->once()->andReturnSelf();

                $scope($query);

                $reordered = true;

                return $repository;
            });

        $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        static::assertTrue($reordered);
    }

    /**
     * Test the limit-capping behaviour of the chunk callback. With a query
     * limit of three and chunks of [2, 2, 1] records, the stream must output
     * the first three records only, include the CSV header exactly once, and
     * stop chunking once the limit is reached.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @return void
     */
    public function testStreamRepositoryToCsvCapsOutputAtQueryLimit(): void
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb(), ['limit' => '3']);
        ApiQuery::parse($request);

        $users = collect(['UserA', 'UserB', 'UserC', 'UserD', 'UserE'])
            ->map(fn (string $name) => User::create(['name' => $name, 'email' => strtolower($name) . '@stream.com']));

        $chunks = [
            collect([$users[0], $users[1]]),
            collect([$users[2], $users[3]]),
            collect([$users[4]]),
        ];

        $returns = [];

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')
            ->andReturnUsing(function (int $_size, callable $callback) use ($chunks, &$returns): bool {
                foreach ($chunks as $chunk) {
                    $returns[] = $callback($chunk);
                }

                return true;
            });

        $without_headers_calls = 0;

        /** @var \Mockery\MockInterface $chain */
        $chain = \Mockery::mock();
        $chain->shouldReceive('withoutFields')->andReturnSelf();
        $chain->shouldReceive('withoutHeaders')
            ->andReturnUsing(function () use (&$without_headers_calls, &$chain): MockInterface {
                $without_headers_calls++;

                return $chain;
            });
        $chain->shouldReceive('exportArray')
            ->andReturnUsing(fn (array $rows): string => implode("\n", array_column($rows, 'name')) . "\n");

        /** @var \Mockery\MockInterface $exporter */
        $exporter = \Mockery::mock();
        $exporter->shouldReceive('format')->andReturn($chain);

        Exporter::swap($exporter);

        FunctionOverrides::set('flush', fn () => null);
        FunctionOverrides::set('ob_flush', fn () => null);

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        ob_start();
        $response->sendContent();
        $output = (string) ob_get_clean();

        static::assertStringContainsString('UserA', $output);
        static::assertStringContainsString('UserB', $output);
        static::assertStringContainsString('UserC', $output);
        static::assertStringNotContainsString('UserD', $output);
        static::assertStringNotContainsString('UserE', $output);

        static::assertSame([true, true, false], $returns);
        static::assertSame(1, $without_headers_calls);
    }

    /**
     * Test that the chunk callback flushes the active output buffer after
     * emitting each chunk.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @return void
     */
    public function testChunkOutputFlushesActiveOutputBuffer(): void
    {
        $counts = $this->streamSingleChunkWithBufferLevel(1);

        static::assertSame(1, $counts['ob_flush']);
        static::assertSame(1, $counts['flush']);
    }

    /**
     * Test that the chunk callback skips ob_flush when no output buffer is
     * active, while still flushing the system buffer.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @return void
     */
    public function testChunkOutputSkipsObFlushWhenNoBufferIsActive(): void
    {
        $counts = $this->streamSingleChunkWithBufferLevel(0);

        static::assertSame(0, $counts['ob_flush']);
        static::assertSame(1, $counts['flush']);
    }

    /**
     * Test that makeTransformer is callable from a subclass and produces a
     * closure that resolves models through the repository resource class.
     *
     * @return void
     */
    public function testMakeTransformerIsCallableFromSubclass(): void
    {
        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        $user = User::create(['name' => 'Transformed', 'email' => 'transformed@example.com']);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);

        $controller = new class extends TestingExportController {
            /**
             * @param  \SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model>  $repository
             * @return \Closure(array<int, \Illuminate\Database\Eloquent\Model>): array<int, array<string, mixed>>
             */
            public function callMakeTransformer(ApiRepository $repository): \Closure
            {
                return $this->makeTransformer($repository);
            }
        };

        $transformer = $controller->callMakeTransformer($repository);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $transformer([$user]);

        static::assertSame('Transformed', $rows[0]['name']);
    }

    /**
     * Test that formatChunkAsCsv is callable from a subclass and toggles the
     * first-chunk flag.
     *
     * @return void
     */
    public function testFormatChunkAsCsvIsCallableFromSubclass(): void
    {
        /** @var \Mockery\MockInterface $chain_mock */
        $chain_mock = \Mockery::mock();
        $chain_mock->shouldReceive('withoutFields')->andReturnSelf();
        $chain_mock->shouldReceive('exportArray')->once()->andReturn("name\nJohn\n");

        /** @var \Mockery\MockInterface $facade_mock */
        $facade_mock = \Mockery::mock();
        $facade_mock->shouldReceive('format')->with('csv')->once()->andReturn($chain_mock);

        Exporter::swap($facade_mock);

        $controller = new class extends TestingExportController {
            /**
             * @param  array<int, array<string, mixed>>  $rows
             * @param  bool  $is_first_chunk
             * @return string
             */
            public function callFormatChunkAsCsv(array $rows, bool &$is_first_chunk): string
            {
                return $this->formatChunkAsCsv($rows, $is_first_chunk);
            }
        };

        $is_first = true;

        $result = $controller->callFormatChunkAsCsv([['name' => 'John']], $is_first);

        static::assertStringContainsString('John', $result);
        // @phpstan-ignore staticMethod.impossibleType
        static::assertFalse($is_first);
    }

    /**
     * Test that createStreamedResponse is callable from a subclass.
     *
     * @return void
     */
    public function testCreateStreamedResponseIsCallableFromSubclass(): void
    {
        $controller = new class extends TestingExportController {
            /**
             * @param  callable(): void  $callback
             * @param  string  $content_type
             * @param  string  $filename
             * @return \Symfony\Component\HttpFoundation\StreamedResponse
             */
            public function callCreateStreamedResponse(
                callable $callback,
                string $content_type,
                string $filename
            ): StreamedResponse {
                return $this->createStreamedResponse($callback, $content_type, $filename);
            }
        };

        $response = $controller->callCreateStreamedResponse(static function (): void {
            echo 'chunk';
        }, 'text/csv', 'report.csv');

        static::assertSame('text/csv', $response->headers->get('Content-Type'));
        static::assertStringContainsString('report.csv', $response->headers->get('Content-Disposition') ?? '');
    }

    /**
     * Stream a single chunk with the given simulated output buffer level and
     * return the recorded flush call counts.
     *
     * @SuppressWarnings("php:S1172")
     *
     * @param  int  $buffer_level
     * @return array{ob_flush: int, flush: int}
     */
    private function streamSingleChunkWithBufferLevel(int $buffer_level): array
    {
        $controller = $this->createControllerWithTrait();

        $request = Request::create(self::TEST_URI, HttpMethod::GET->getVerb());
        ApiQuery::parse($request);

        $user = User::create(['name' => 'Flushed', 'email' => 'flushed@example.com']);

        /** @var \Mockery\MockInterface&\SineMacula\ApiToolkit\Repositories\ApiRepository<\Illuminate\Database\Eloquent\Model> $repository */
        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('addScope')->andReturnSelf();
        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')
            ->andReturnUsing(function (int $_size, callable $callback) use ($user): bool {
                $callback(collect([$user]));

                return true;
            });

        /** @var \Mockery\MockInterface $chain */
        $chain = \Mockery::mock();
        $chain->shouldReceive('withoutFields')->andReturnSelf();
        $chain->shouldReceive('withoutHeaders')->andReturnSelf();
        $chain->shouldReceive('exportArray')->andReturn("id,name\n1,Flushed\n");

        /** @var \Mockery\MockInterface $exporter */
        $exporter = \Mockery::mock();
        $exporter->shouldReceive('format')->andReturn($chain);

        Exporter::swap($exporter);

        $counts = ['ob_flush' => 0, 'flush' => 0];

        FunctionOverrides::set('ob_get_level', fn (): int => $buffer_level);
        FunctionOverrides::set('ob_flush', function () use (&$counts): void {
            $counts['ob_flush']++;
        });
        FunctionOverrides::set('flush', function () use (&$counts): void {
            $counts['flush']++;
        });

        $response = $controller->streamRepositoryToCsv($repository); // @phpstan-ignore method.notFound

        ob_start();
        $response->sendContent();
        ob_end_clean();

        return $counts;
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
