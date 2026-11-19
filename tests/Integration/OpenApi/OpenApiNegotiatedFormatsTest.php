<?php

declare(strict_types = 1);

namespace Tests\Integration\OpenApi;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\OpenApi\Builder\SuccessResponseBuilder;
use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\OpenApi\ExportableUserController;
use Tests\Fixtures\Resources\ExportableUserResource;
use Tests\TestCase;

/**
 * Pins that the document describes the JSON representation only, though the
 * resource exporter answers an index in other negotiated formats.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(SuccessResponseBuilder::class)]
#[Group('exporter')]
final class OpenApiNegotiatedFormatsTest extends TestCase
{
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

        Config::set('api-toolkit.resources.resource_map', [User::class => ExportableUserResource::class]);

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);

        Route::get('users', [ExportableUserController::class, 'index']);
    }

    /**
     * Provide the media types the exporter negotiates beside JSON.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function negotiatedFormatProvider(): iterable
    {
        yield 'csv' => ['text/csv', 'text/csv; charset=UTF-8'];
        yield 'xml' => ['application/xml', 'application/xml; charset=UTF-8'];
        yield 'ndjson' => ['application/x-ndjson', 'application/x-ndjson; charset=UTF-8'];
    }

    /**
     * Test that an index answered in a negotiated format is documented with the
     * JSON media type only.
     *
     * @param  string  $accept
     * @param  string  $contentType
     * @return void
     */
    #[DataProvider('negotiatedFormatProvider')]
    public function testNegotiatedFormatIsAnsweredButOnlyJsonIsDocumented(string $accept, string $contentType): void
    {
        $this->get('/users', ['Accept' => $accept])->assertOk()->assertHeader('Content-Type', $contentType);

        $content = $this->app()->make(ExportOpenApiComponents::class)->export()->document['paths']['/users']['get']['responses'][200]['content'];

        self::assertIsArray($content);
        self::assertSame(['application/json'], array_keys($content));
    }

    /**
     * Get the package providers, adding the resource exporter.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ExporterServiceProvider::class,
        ];
    }

    /**
     * Get the application instance.
     *
     * @return \Illuminate\Foundation\Application
     */
    private function app(): Application
    {
        assert($this->app instanceof Application);

        return $this->app;
    }
}
