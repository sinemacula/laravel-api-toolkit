<?php

declare(strict_types = 1);

namespace Tests\Integration\Exports;

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SineMacula\ApiToolkit\Http\Routing\Controller;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Fixtures\Controllers\ExportingUserController;
use Tests\Fixtures\Models\User;
use Tests\TestCase;

/**
 * Integration tests for exports negotiated through the base controller's
 * resource helpers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Controller::class)]
#[Group('exporter')]
final class ControllerExportTest extends TestCase
{
    /** @var \Tests\Fixtures\Models\User */
    private User $alice;

    /** @var \Tests\Fixtures\Models\User */
    private User $bob;

    /**
     * Set up the controller routes and seed users.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Route::apiResource('users', ExportingUserController::class)->only(['index', 'show'])->middleware(SubstituteBindings::class);
        Route::get('/basic-users', [ExportingUserController::class, 'basic']);

        $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'status' => 'active']);
        $this->bob   = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'active']);
    }

    /**
     * Test that a JSON collection keeps the helper's status, header and the
     * toolkit envelope.
     *
     * @return void
     */
    public function testJsonCollectionKeepsStatusHeaderAndEnvelope(): void
    {
        $this->getJson('/users')
            ->assertStatus(202)
            ->assertHeader(ExportingUserController::HEADER, 'index')
            ->assertHeader('Total-Count', '2')
            ->assertJsonPath('meta', ['total' => 2, 'total_capped' => false, 'count' => 2, 'continue' => false])
            ->assertJsonPath('data.1.name', 'Bob');
    }

    /**
     * Test that a JSON item keeps the helper's status and header.
     *
     * @return void
     */
    public function testJsonItemKeepsStatusAndHeader(): void
    {
        $this->getJson('/users/' . $this->alice->id)
            ->assertCreated()
            ->assertHeader(ExportingUserController::HEADER, 'show')
            ->assertExactJson(['data' => ['_type' => 'exportable_users', 'id' => $this->alice->id, 'name' => 'Alice', 'email' => 'alice@example.com']]);
    }

    /**
     * Test that a collection exported as CSV streams with the helper's status
     * and header.
     *
     * @return void
     */
    public function testCsvCollectionStreamsWithStatusAndHeader(): void
    {
        $response = $this->get('/users', ['Accept' => 'text/csv'])
            ->assertStatus(202)
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader(ExportingUserController::HEADER, 'index');

        self::assertSame(sprintf("Id,Name,Email\n%d,Alice,alice@example.com\n%d,Bob,bob@example.com\n", $this->alice->id, $this->bob->id), $response->streamedContent());
    }

    /**
     * Test that an item exported as TSV streams with the helper's status and
     * header.
     *
     * @return void
     */
    public function testTsvItemStreamsWithStatusAndHeader(): void
    {
        $response = $this->get('/users/' . $this->bob->id . '?format=tsv')
            ->assertCreated()
            ->assertHeader('Content-Type', 'text/tab-separated-values; charset=UTF-8')
            ->assertHeader(ExportingUserController::HEADER, 'show');

        self::assertSame(sprintf("Id\tName\tEmail\n%d\tBob\tbob@example.com\n", $this->bob->id), $response->streamedContent());
    }

    /**
     * Test that a tabular format for a resource with no tabular schema is not
     * acceptable.
     *
     * @return void
     */
    public function testTabularFormatWithoutTabularSchemaIsNotAcceptable(): void
    {
        $this->get('/basic-users', ['Accept' => 'text/csv'])->assertStatus(406);
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
}
