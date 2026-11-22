<?php

declare(strict_types = 1);

namespace Tests\Integration\Services\Input;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Providers\Registrars\ContainerBindingRegistrar;
use SineMacula\ApiToolkit\Services\Input\Payload;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Controllers\StorePayloadController;
use Tests\Fixtures\Input\AttachmentPayload;
use Tests\Fixtures\Input\OptionalRulePayload;
use Tests\Fixtures\Input\StorePayload;
use Tests\Fixtures\Input\UpdateUserPayload;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Repositories\UserRepository;
use Tests\Fixtures\Services\Input\Enums\StubStatusEnum;
use Tests\TestCase;

/**
 * End-to-end tests that a type-hinted Payload is resolved over the wire.
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
     * Set up each test with the toolkit handler and payload-backed routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::post('/store-payload', StorePayloadController::class);
        Route::get('/store-payload', StorePayloadController::class);
        Route::get('/query-payload', static fn (Request $request): JsonResponse => new JsonResponse(StorePayload::from($request->query->all())->toArray()));
        Route::post('/attachment', static fn (AttachmentPayload $payload): JsonResponse => new JsonResponse(['name' => $payload->attachment->getClientOriginalName()]));
        Route::post('/optional-rule', static fn (OptionalRulePayload $payload): JsonResponse => new JsonResponse(['display_name' => $payload->displayName]));

        Route::patch('/users/{user}', static function (int $user, UpdateUserPayload $payload, UserRepository $users): JsonResponse {

            $users->persist(User::query()->findOrFail($user), $payload->toArray());

            return new JsonResponse($payload->toArray());
        });
    }

    /**
     * Test that a valid request resolves and injects a hydrated payload.
     *
     * @return void
     */
    public function testValidRequestInjectsHydratedPayload(): void
    {
        $response = $this->postJson('/store-payload', [
            'title'      => 'Widget',
            'quantity'   => 5,
            'status'     => 'active',
            'unit_price' => 1250,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Widget');
        $response->assertJsonPath('quantity', 5);
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('unit_price', 1250);
    }

    /**
     * Test that an invalid request yields a 422, not a 500.
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
     * Test that a field sent only in the query string is reported missing, and
     * that a body field wins over a same-named query field.
     *
     * @return void
     */
    public function testTypeHintedPayloadNeverReadsTheQueryString(): void
    {
        $this->get('/store-payload?title=Widget')->assertStatus(422)->assertJsonPath('error.meta.title.0', 'The title field is required.');
        $this->postJson('/store-payload?title=Widget', [])->assertStatus(422)->assertJsonPath('error.meta.title.0', 'The title field is required.');

        $response = $this->postJson('/store-payload?title=Spoofed&quantity=5', ['title' => 'Widget']);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Widget');
        $response->assertJsonPath('quantity', null);
    }

    /**
     * Test that an uploaded file validates and hydrates a type-hinted payload.
     *
     * @return void
     */
    public function testUploadedFileHydratesATypeHintedPayload(): void
    {
        $this->post('/attachment', ['attachment' => UploadedFile::fake()->create('spec.pdf')])->assertExactJson(['name' => 'spec.pdf']);
        $this->post('/attachment')->assertStatus(422)->assertJsonPath('error.meta.attachment.0', 'The attachment field is required.');
    }

    /**
     * Test that form, multipart and query string scalars hydrate the payload's
     * int properties.
     *
     * @return void
     */
    public function testFormEncodedRequestHydratesScalarProperties(): void
    {
        $body = ['title' => 'Widget', 'quantity' => '5', 'unit_price' => '1250'];

        $responses = [
            'form'      => $this->post('/store-payload', $body),
            'multipart' => $this->post('/store-payload', $body + ['attachment' => UploadedFile::fake()->create('spec.pdf')]),
            'query'     => $this->get('/query-payload?' . http_build_query($body)),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(200);
            $response->assertJsonPath('quantity', 5);
            $response->assertJsonPath('unit_price', 1250);
        }
    }

    /**
     * Test that a payload whose rule lets a required parameter be absent fails
     * as a server error rather than a validation failure.
     *
     * @return void
     */
    public function testOptionalRuleOnARequiredParameterYields500(): void
    {
        $this->postJson('/optional-rule', ['display_name' => 'Ada'])->assertExactJson(['display_name' => 'Ada']);

        $response = $this->postJson('/optional-rule', []);

        $response->assertStatus(500);
        $response->assertJsonPath('error.status', 500);
    }

    /**
     * Test that a partial update persists the fields it sends and leaves the
     * rest of the row untouched.
     *
     * @return void
     */
    public function testPartialUpdateLeavesTheFieldsItOmitsUntouched(): void
    {
        $user = User::query()->create(['name' => 'Original', 'email' => 'old@example.com']);

        $response = $this->patchJson('/users/' . $user->id, ['email' => 'new@example.com']);

        $response->assertStatus(200);
        $response->assertExactJson(['email' => 'new@example.com']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Original', 'email' => 'new@example.com']);
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
        self::assertNull($fromArray->unitPrice);

        $fromRequest = StorePayload::from(Request::create('/', 'POST', ['title' => 'Gizmo', 'unit_price' => 499]));

        self::assertSame('Gizmo', $fromRequest->title);
        self::assertSame(499, $fromRequest->unitPrice);
        self::assertSame(['title' => 'Gizmo', 'unit_price' => 499], $fromRequest->toArray());
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
