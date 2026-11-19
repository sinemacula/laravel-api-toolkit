<?php

declare(strict_types = 1);

namespace Tests\Feature\Exports;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Models\Organization;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Resources\ExportableUserResource;
use Tests\Fixtures\Resources\OrganizationResource;
use Tests\ShippedDefaultsTestCase;

/**
 * Feature tests exporting a criteria-backed index, whose columns are narrowed
 * to the selected fields, on the shipped configuration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiResource::class)]
#[CoversTrait(DerivesTabularSchema::class)]
#[Group('exporter')]
final class CriteriaExportHttpTest extends ShippedDefaultsTestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up the criteria-backed index and seed users.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::get('/users', static fn (UserRepository $repository): ApiResourceCollection => new ApiResourceCollection(
            $repository->usingResource(ExportableUserResource::class)->withApiCriteria()->paginate(),
            ExportableUserResource::class,
        ));

        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'status' => 'active']);
    }

    /**
     * Test that an export without fields has the default columns, each cell
     * holding the value the JSON response renders.
     *
     * @return void
     */
    public function testDefaultExportFillsEveryColumnWithTheJsonValue(): void
    {
        $rows = $this->exportRows('');

        self::assertSame(['Id', 'Name', 'Email'], array_shift($rows));
        self::assertSame($this->jsonRows('', ['id', 'name', 'email']), $rows);
    }

    /**
     * Provide the tabular formats with their field separators.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function tabularFormats(): iterable
    {
        yield 'csv' => ['csv', ','];
        yield 'tsv' => ['tsv', "\t"];
    }

    /**
     * Test that the all selector exports every scalar field the JSON response
     * renders, relations aside.
     *
     * @param  string  $format
     * @param  string  $separator
     * @return void
     */
    #[DataProvider('tabularFormats')]
    public function testAllSelectorExportsEveryScalarFieldTheJsonRenders(string $format, string $separator): void
    {
        $query = 'fields[exportable_users]=:all';
        $keys  = ['id', 'name', 'email', 'created_at', 'label'];
        $rows  = $this->exportRows($query, $format, $separator);

        self::assertSame(['Id', 'Name', 'Email', 'Created At', 'Label'], array_shift($rows));
        self::assertSame($this->jsonRows($query, $keys), $rows);
        self::assertEqualsCanonicalizing(['_type', ...$keys, 'organization'], array_keys((array) $this->getJson('/users?' . $query)->json('data.0')));
    }

    /**
     * Test that an explicit field list exports exactly those columns, in the
     * order requested.
     *
     * @return void
     */
    public function testExplicitFieldsExportExactlyThoseColumnsInRequestOrder(): void
    {
        $query = 'fields[exportable_users]=email,name';
        $rows  = $this->exportRows($query);

        self::assertSame(['Email', 'Name'], array_shift($rows));
        self::assertSame($this->jsonRows($query, ['email', 'name']), $rows);
    }

    /**
     * Test that an unknown field is refused for an export as it is for JSON.
     *
     * @return void
     */
    public function testUnknownFieldIsRefusedAsForJson(): void
    {
        $query = '/users?fields[exportable_users]=name,nickname';

        $this->getJson($query)->assertStatus(422);
        $this->get($query, ['Accept' => 'text/csv'])->assertStatus(422);
    }

    /**
     * Test that filters and order decide the exported rows as they decide the
     * JSON rows.
     *
     * @return void
     */
    public function testFiltersAndOrderShapeTheExportAsTheyShapeJson(): void
    {
        $query = 'filters=' . urlencode('{"name":{"$in":["Alice","Carol"]}}') . '&order=name:desc&fields[exportable_users]=name';
        $rows  = $this->exportRows($query);

        array_shift($rows);

        self::assertSame([['Carol'], ['Alice']], $rows);
        self::assertSame($this->jsonRows($query, ['name']), $rows);
    }

    /**
     * Test that limit and page decide which rows are exported, an export being
     * one page.
     *
     * @return void
     */
    public function testLimitAndPageDecideTheExportedPage(): void
    {
        $query = 'order=name&limit=1&page=2&fields[exportable_users]=name';
        $rows  = $this->exportRows($query);

        array_shift($rows);

        self::assertSame([['Bob']], $rows);
    }

    /**
     * Test that the format parameter exports the same bytes as the Accept
     * header.
     *
     * @return void
     */
    public function testFormatParameterMatchesTheAcceptHeader(): void
    {
        $header    = $this->get('/users', ['Accept' => 'text/csv'])->assertOk()->streamedContent();
        $parameter = $this->get('/users?format=csv')->assertOk()->streamedContent();

        self::assertSame($header, $parameter);
    }

    /**
     * Define the environment setup.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        $config->set('api-toolkit.resources.resource_map', [
            User::class         => ExportableUserResource::class,
            Organization::class => OrganizationResource::class,
        ]);
    }

    /**
     * Register the exporter service provider alongside the toolkit.
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
     * Export the index in the given format and split it into rows of cells, the
     * header first.
     *
     * @param  string  $query
     * @param  string  $format
     * @param  string  $separator
     * @return list<list<string|null>>
     */
    private function exportRows(string $query, string $format = 'csv', string $separator = ','): array
    {
        $content = $this->get('/users?format=' . $format . '&' . $query)->assertOk()->streamedContent();

        return array_map(
            static fn (string $line): array => str_getcsv($line, $separator, '"', ''),
            explode("\n", rtrim($content, "\n")),
        );
    }

    /**
     * Render the index as JSON and read the given keys of each item as the text
     * an export writes.
     *
     * @param  string  $query
     * @param  list<string>  $keys
     * @return list<list<string>>
     */
    private function jsonRows(string $query, array $keys): array
    {
        /** @var list<array<string, int|string>> $items */
        $items = $this->getJson('/users?' . $query)->assertOk()->json('data');

        return array_map(
            static fn (array $item): array => array_map(static fn (string $key): string => (string) $item[$key], $keys),
            $items,
        );
    }
}
