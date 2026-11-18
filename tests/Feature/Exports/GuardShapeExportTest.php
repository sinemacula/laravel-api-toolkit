<?php

declare(strict_types = 1);

namespace Tests\Feature\Exports;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Exceptions\PerItemGuardedFieldException;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Enums\UserStatus;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\GuardShapeExportResource;
use Tests\TestCase;

/**
 * Feature tests for each guard shape consumers write, exported as CSV beside
 * the JSON the same caller receives.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(PerItemGuardedFieldException::class)]
#[CoversTrait(DerivesTabularSchema::class)]
#[Group('exporter')]
final class GuardShapeExportTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var array<int, string> The email of every seeded user */
    private const array EMAILS = ['admin@example.com', 'alice@example.com', 'bob@example.com'];

    /**
     * Set up the exporter, the gates, seeded users and the export route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        SchemaCompiler::clearCache();

        $this->registerApiExceptionHandler();

        Config::set(ResourceDiscovery::RESOURCES_KEY, [GuardShapeExportResource::class]);

        Gate::define('view-email', static fn (?User $caller, User $row): bool => $row->getAttributeValue('status') === UserStatus::ACTIVE);
        Gate::define('view-emails', static fn (?User $caller): bool => $caller?->is_admin === true);

        User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'status' => 'active']);
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'inactive']);

        Route::middleware(ParseApiQuery::class)->get(
            '/guard-shapes',
            static fn (): AnonymousResourceCollection => GuardShapeExportResource::collection(User::query()->orderBy('id')->get()),
        );
    }

    /**
     * Tear down, clearing the schema compiler cache.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        SchemaCompiler::clearCache();

        parent::tearDown();
    }

    /**
     * Provide each row guard shape with the emails JSON shows each caller.
     *
     * @return iterable<string, array{string, bool, list<string>}>
     */
    public static function rowGuardShapes(): iterable
    {
        $active = ['admin@example.com', 'alice@example.com'];

        yield 'typed resource and request, guest' => ['typed_email', false, []];
        yield 'typed resource and request, admin' => ['typed_email', true, self::EMAILS];
        yield 'typed concrete resource, guest' => ['concrete_email', false, $active];
        yield 'typed concrete resource, admin' => ['concrete_email', true, $active];
        yield 'type checked then row read, guest' => ['checked_email', false, $active];
        yield 'type checked then row read, admin' => ['checked_email', true, $active];
        yield 'gate over the row, guest' => ['gated_email', false, $active];
        yield 'gate over the row, admin' => ['gated_email', true, $active];
    }

    /**
     * Test that a row guard refuses the export before any row is written,
     * whatever its shape and whoever asks, while JSON honours it per row.
     *
     * @param  string  $field
     * @param  bool  $asAdmin
     * @param  list<string>  $shown
     * @return void
     */
    #[DataProvider('rowGuardShapes')]
    public function testRowGuardRefusesTheExportWithoutRowData(string $field, bool $asAdmin, array $shown): void
    {
        $this->actAs($asAdmin);

        self::assertSame($shown, array_values(array_filter(array_column($this->jsonRows($field), $field), is_string(...))));

        $csv = $this->export($field);

        $csv->assertServerError();

        self::assertStringNotContainsStringIgnoringCase('csv', (string) $csv->headers->get('Content-Type'));

        foreach (self::EMAILS as $email) {
            $csv->assertDontSee($email);
        }
    }

    /**
     * Provide each caller guard shape for a guest and an admin.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function callerGuardShapes(): iterable
    {
        yield 'typed request, guest' => ['caller_email', false];
        yield 'typed request, admin' => ['caller_email', true];
        yield 'gate, guest' => ['gated_caller_email', false];
        yield 'gate, admin' => ['gated_caller_email', true];
    }

    /**
     * Test that a caller guard exports exactly the cells JSON shows the same
     * caller, its column after the id and the name.
     *
     * @param  string  $field
     * @param  bool  $asAdmin
     * @return void
     */
    #[DataProvider('callerGuardShapes')]
    public function testCallerGuardExportsWhatJsonShowsTheSameCaller(string $field, bool $asAdmin): void
    {
        $this->actAs($asAdmin);

        $json = array_map(static fn (array $row): mixed => $row[$field] ?? null, $this->jsonRows($field));

        $csv = $this->export($field);

        $csv->assertOk();

        $lines = array_filter(explode("\n", $csv->streamedContent()), static fn (string $line): bool => $line !== '');
        $cells = array_map(static fn (string $line): ?string => str_getcsv($line, escape: '\\')[2] ?? null, array_slice(array_values($lines), 1));

        self::assertSame($json, $cells);
        self::assertSame($asAdmin ? self::EMAILS : [null, null, null], $cells);
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
     * Act as the seeded admin, or as a guest.
     *
     * @param  bool  $asAdmin
     * @return void
     */
    private function actAs(bool $asAdmin): void
    {
        if (!$asAdmin) {
            return;
        }

        $this->actingAs(User::query()->where('email', 'admin@example.com')->firstOrFail());
    }

    /**
     * Read the JSON rows selecting the id, the name and the given field.
     *
     * @param  string  $field
     * @return list<array<string, mixed>>
     */
    private function jsonRows(string $field): array
    {
        $response = $this->getJson('/guard-shapes?fields[guard_shape_exports]=id,name,' . $field);

        $response->assertOk();

        /** @var list<array<string, mixed>> */
        return $response->json('data');
    }

    /**
     * Request the CSV export selecting the id, the name and the given field.
     *
     * @param  string  $field
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function export(string $field): TestResponse
    {
        return $this->get('/guard-shapes?fields[guard_shape_exports]=id,name,' . $field, ['Accept' => 'text/csv']);
    }
}
