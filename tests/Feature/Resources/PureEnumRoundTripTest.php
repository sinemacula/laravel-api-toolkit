<?php

declare(strict_types = 1);

namespace Tests\Feature\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\CoversTrait;
use SineMacula\ApiToolkit\Http\Middleware\ParseApiQuery;
use SineMacula\ApiToolkit\Http\Resources\ApiResourceCollection;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Http\Resources\Concerns\ValueResolver;
use SineMacula\ApiToolkit\Repositories\Concerns\AttributeSetter;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\FilterValueGuard;
use SineMacula\ApiToolkit\Repositories\Criteria\Concerns\PureEnumFilterValue;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;
use SineMacula\Exporter\ExporterServiceProvider;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Enums\Platform;
use Tests\Fixtures\Input\DevicePayload;
use Tests\Fixtures\Models\Device;
use Tests\Fixtures\Repositories\DeviceRepository;
use Tests\Fixtures\Resources\DeviceResource;
use Tests\TestCase;

/**
 * Feature tests for a pure enum's lowercase wire name across a read, a filter,
 * a write and an export.
 *
 * Eloquent stores the declared case name, so every surface a client sees must
 * translate it the same way for a value read to be sent back unchanged.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValueResolver::class)]
#[CoversClass(FilterValueGuard::class)]
#[CoversClass(PureEnumFilterValue::class)]
#[CoversClass(PureEnum::class)]
#[CoversClass(AttributeSetter::class)]
#[CoversTrait(DerivesTabularSchema::class)]
#[Group('exporter')]
final class PureEnumRoundTripTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Register the device routes and seed a device on each of two platforms.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(ParseApiQuery::class)->get('/devices', static function (DeviceRepository $repository): ApiResourceCollection {

            $devices = $repository->usingResource(DeviceResource::class)->withApiCriteria()->paginate();

            return new ApiResourceCollection($devices, DeviceResource::class);
        });

        Route::get('/devices/export', static fn (): AnonymousResourceCollection => DeviceResource::collection(Device::query()->orderBy('id')->get()));

        Route::post('/devices', static function (Request $request, DeviceRepository $repository): DeviceResource {

            $device = new Device;

            $repository->persist($device, DevicePayload::from($request)->toArray());

            return new DeviceResource($device);
        });

        Device::create(['name' => 'Browser', 'platform' => Platform::WEB]);
        Device::create(['name' => 'Phone', 'platform' => Platform::MOBILE_APP]);
    }

    /**
     * Test that a stored case name is read as its lowercase wire name.
     *
     * @return void
     */
    public function testStoredCaseNameIsReadAsItsWireName(): void
    {
        self::assertSame('MOBILE_APP', DB::table('devices')->where('name', 'Phone')->value('platform'));

        $this->getJson('/devices')
            ->assertOk()
            ->assertJsonPath('data.*.platform', ['web', 'mobile_app']);
    }

    /**
     * Test that a filter matches by wire name and refuses the case name.
     *
     * @return void
     */
    public function testFilterMatchesByWireNameAndRefusesTheCaseName(): void
    {
        $this->getJson('/devices?filters=' . urlencode('{"platform":{"$in":["web","mobile_app"]}}'))
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Browser', 'Phone']);

        $this->getJson('/devices?filters=' . urlencode('{"platform":"MOBILE_APP"}'))
            ->assertStatus(422)
            ->assertJsonPath('error.meta', ['filters.platform' => ['The $eq condition on "platform" must be one of web, mobile_app, desktop.']]);
    }

    /**
     * Test that a wire name written through a payload is stored as the case
     * name and read back unchanged.
     *
     * @return void
     */
    public function testWireNameWrittenIsStoredAsTheCaseNameAndReadBack(): void
    {
        $this->postJson('/devices', ['name' => 'Workstation', 'platform' => 'desktop'])
            ->assertSuccessful()
            ->assertJsonPath('data.platform', 'desktop');

        self::assertSame('DESKTOP', DB::table('devices')->where('name', 'Workstation')->value('platform'));

        $this->getJson('/devices?filters=' . urlencode('{"platform":"desktop"}'))
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Workstation']);

        $this->postJson('/devices', ['name' => 'Kiosk', 'platform' => 'DESKTOP'])->assertStatus(422);
    }

    /**
     * Test that an export cell holds the wire name.
     *
     * @return void
     */
    public function testExportCellHoldsTheWireName(): void
    {
        $response = $this->get('/devices/export', ['Accept' => 'text/csv']);

        $response->assertOk();

        $content = $response->streamedContent();

        self::assertStringContainsString('mobile_app', $content);
        self::assertStringNotContainsString('MOBILE_APP', $content);
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
