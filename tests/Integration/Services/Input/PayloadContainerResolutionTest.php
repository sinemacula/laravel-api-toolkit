<?php

declare(strict_types = 1);

namespace Tests\Integration\Services\Input;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Services\Input\Payload;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Controllers\StorePayloadController;
use Tests\Fixtures\Input\StorePayload;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
use Tests\TestCase;

/**
 * End-to-end proof that a type-hinted Payload is resolved over the wire.
 *
 * A controller action type-hints a concrete Payload subclass; a valid request
 * yields a hydrated instance injected into the action, while an invalid request
 * surfaces as a 422 through the exception handler rather than a 500. Direct
 * from() and named-argument construction remain unaffected by the hook.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ContainerBindingRegistrar::class)]
#[CoversClass(Payload::class)]
final class PayloadContainerResolutionTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with the toolkit handler and a payload-backed route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::post('/store-payload', StorePayloadController::class);
    }

    /**
     * Test that a valid request resolves and injects a hydrated payload.
     *
     * @return void
     */
    public function testValidRequestInjectsHydratedPayload(): void
    {
        $response = $this->postJson('/store-payload', [
            'title'    => 'Widget',
            'quantity' => 5,
            'status'   => 'active',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Widget');
        $response->assertJsonPath('quantity', 5);
        $response->assertJsonPath('status', 'active');
    }

    /**
     * Test that an invalid request yields a 422 with the expected error keys
     * rather than a 500 from an unhandled resolution failure.
     *
     * @return void
     */
    public function testInvalidRequestYields422(): void
    {
        $response = $this->postJson('/store-payload', ['quantity' => 3]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.status', 422);

        $errors = $response->json('error.meta.title');

        self::assertIsArray($errors);
        self::assertNotEmpty($errors);
    }

    /**
     * Test that direct from() construction is unchanged by the container hook.
     *
     * @return void
     */
    public function testFromArrayAndRequestStillWork(): void
    {
        $fromArray = StorePayload::from(['title' => 'Gadget', 'status' => 'inactive']);

        self::assertSame('Gadget', $fromArray->title);
        self::assertNull($fromArray->quantity);
        self::assertSame(StubStatusEnum::INACTIVE, $fromArray->status);

        $fromRequest = StorePayload::from(Request::create('/', 'GET', ['title' => 'Gizmo']));

        self::assertSame('Gizmo', $fromRequest->title);
    }

    /**
     * Test that named-argument construction is unchanged by the container hook.
     *
     * @return void
     */
    public function testNamedArgumentConstructionStillWorks(): void
    {
        $payload = new StorePayload(title: 'Manual', quantity: 9, status: StubStatusEnum::ACTIVE);

        self::assertSame('Manual', $payload->title);
        self::assertSame(9, $payload->quantity);
        self::assertSame(StubStatusEnum::ACTIVE, $payload->status);
    }
}
