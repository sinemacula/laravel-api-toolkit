<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use SineMacula\ApiToolkit\Http\Controllers\RespondsWithExport;
use SineMacula\ApiToolkit\Http\Controllers\RespondsWithStream;
use SineMacula\ApiToolkit\Repositories\ApiRepository;
use SineMacula\Exporter\Facades\Exporter;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * @internal
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ControllerExportAndStreamTraitTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testExportTraitSupportsCsvAndXmlForArraysCollectionsAndItems(): void
    {
        $controller = new ExportAndStreamHarness;
        $fake       = new FakeExporterManager;

        $fake->arrayOutputs      = ["id,name\n1,Alice\n", '<items/>'];
        $fake->collectionOutputs = ["id,name\n1,Alice\n", '<items/>'];
        $fake->itemOutputs       = ["id,name\n1,Alice\n", '<item/>'];

        Exporter::swap($fake);

        config()->set('api-toolkit.exports.ignored_fields', ['_type']);

        $rows = [['id' => 1, 'name' => 'Alice']];

        $response = $controller->exportArrayToCsv($rows);

        static::assertSame('text/csv', $response->headers->get('Content-Type'));
        static::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        $xmlResponse = $controller->exportArrayToXml($rows, false);

        static::assertSame('application/xml', $xmlResponse->headers->get('Content-Type'));
        static::assertNull($xmlResponse->headers->get('Content-Disposition'));

        $collection = UserResource::collection(new Collection([
            (object) ['id' => 1, 'name' => 'Alice'],
        ]));

        $controller->exportCollectionToCsv($collection);
        $controller->exportCollectionToXml($collection);

        $item = new JsonResource((object) ['id' => 1, 'name' => 'Alice']);

        $controller->exportItemToCsv($item);
        $controller->exportItemToXml($item);

        static::assertContains(['_type'], $fake->withoutFieldsCalls);
    }

    public function testExportTraitThrowsWhenUnsupportedFormatIsRequested(): void
    {
        $controller = new ExportAndStreamHarness;

        $this->app->instance('request', Request::create('/api/users', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));

        $this->expectException(\InvalidArgumentException::class);

        $controller->exportFromArray([['id' => 1]]);
    }

    public function testExportTraitThrowsForUnsupportedCollectionAndItemFormats(): void
    {
        $controller = new ExportAndStreamHarness;

        $this->app->instance('request', Request::create('/api/users', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']));

        $collection = UserResource::collection(new Collection([(object) ['id' => 1, 'name' => 'Alice']]));

        try {
            $controller->exportFromCollection($collection);
            static::fail('Expected InvalidArgumentException for unsupported collection export format.');
        } catch (\InvalidArgumentException) {
            static::assertTrue(true);
        }

        try {
            $controller->exportFromItem(new JsonResource((object) ['id' => 1]));
            static::fail('Expected InvalidArgumentException for unsupported item export format.');
        } catch (\InvalidArgumentException) {
            static::assertTrue(true);
        }
    }

    public function testStreamTraitFormatsCsvChunksAndCanDisableHeadersAfterFirstChunk(): void
    {
        $controller = new ExportAndStreamHarness;
        $fake       = new FakeExporterManager;

        $fake->arrayOutputs = ["id,name\n1,Alice\n", "2,Bob\n"];

        Exporter::swap($fake);

        config()->set('api-toolkit.exports.ignored_fields', ['_type']);

        $isFirstChunk = true;

        $first = $controller->formatChunkAsCsvPublic([['id' => 1, 'name' => 'Alice']], $isFirstChunk);
        $next  = $controller->formatChunkAsCsvPublic([['id' => 2, 'name' => 'Bob']], $isFirstChunk);

        static::assertStringContainsString('id,name', $first);
        static::assertStringContainsString('2,Bob', $next);
        static::assertFalse($isFirstChunk);
        static::assertSame(1, $fake->withoutHeadersCalls);
    }

    public function testStreamTraitCreateTransformerAndStreamResponse(): void
    {
        $controller = new ExportAndStreamHarness;

        $repository = \Mockery::mock(ApiRepository::class);

        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);

        $transformer = $controller->makeTransformerPublic($repository);

        $rows = $transformer([
            new User(['id' => 1, 'name' => 'Alice']),
        ]);

        static::assertSame('Alice', $rows[0]['name']);

        $response = $controller->createStreamedResponsePublic(static function (): void {
            echo 'streamed';
        }, 'text/csv', 'export.csv');

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        static::assertSame('streamed', $content);
    }

    public function testStreamTraitCanStreamRepositoryAndRespectLimit(): void
    {
        $controller = new ExportAndStreamHarness;
        $fake       = new FakeExporterManager;

        $fake->arrayOutputs = ["id,name\n1,Alice\n"];

        Exporter::swap($fake);

        $this->app->instance('request', Request::create('/api/users', 'GET', ['limit' => 1]));
        app(config('api-toolkit.parser.alias'))->parse($this->app['request']);

        $repository = \Mockery::mock(ApiRepository::class);

        $repository->shouldReceive('getResourceClass')->andReturn(UserResource::class);
        $repository->shouldReceive('chunkById')->once()->with(1500, \Mockery::type('callable'))->andReturnUsing(
            function (int $chunkSize, callable $callback): void {
                $result = $callback(new Collection([
                    new User(['id' => 1, 'name' => 'Alice']),
                    new User(['id' => 2, 'name' => 'Bob']),
                ]));

                if ($result !== false) {
                    $callback(new Collection([
                        new User(['id' => 3, 'name' => 'Charlie']),
                    ]));
                }
            },
        );

        $response = $controller->streamRepositoryToCsv($repository);

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        static::assertStringContainsString('Alice', $content);
        static::assertStringNotContainsString('Bob', $content);
        static::assertStringNotContainsString('Charlie', $content);
    }

    public function testStreamTraitThrowsWhenRepositoryResourceClassCannotBeResolved(): void
    {
        $controller = new ExportAndStreamHarness;

        $repository = \Mockery::mock(ApiRepository::class);
        $repository->shouldReceive('getResourceClass')->andReturn(null);

        $this->expectException(\InvalidArgumentException::class);

        $controller->makeTransformerPublic($repository);
    }
}

class ExportAndStreamHarness
{
    use RespondsWithExport;
    use RespondsWithStream;

    public function formatChunkAsCsvPublic(array $rows, bool &$isFirstChunk): string
    {
        return $this->formatChunkAsCsv($rows, $isFirstChunk);
    }

    public function makeTransformerPublic(ApiRepository $repository): \Closure
    {
        return $this->makeTransformer($repository);
    }

    public function createStreamedResponsePublic(callable $callback, string $contentType, string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->createStreamedResponse($callback, $contentType, $filename);
    }
}

class FakeExporterManager
{
    /** @var array<int, string> */
    public array $arrayOutputs = [];

    /** @var array<int, string> */
    public array $collectionOutputs = [];

    /** @var array<int, string> */
    public array $itemOutputs = [];

    /** @var array<int, array|string> */
    public array $withoutFieldsCalls = [];
    public int $withoutHeadersCalls  = 0;

    public function format(?string $name = null): self
    {
        return $this;
    }

    public function withoutFields(array|string $fields): self
    {
        $this->withoutFieldsCalls[] = $fields;

        return $this;
    }

    public function withoutHeaders(): self
    {
        $this->withoutHeadersCalls++;

        return $this;
    }

    public function exportArray(array $rows): string
    {
        return array_shift($this->arrayOutputs) ?? '';
    }

    public function exportCollection($collection): string
    {
        return array_shift($this->collectionOutputs) ?? '';
    }

    public function exportItem($resource): string
    {
        return array_shift($this->itemOutputs) ?? '';
    }
}
