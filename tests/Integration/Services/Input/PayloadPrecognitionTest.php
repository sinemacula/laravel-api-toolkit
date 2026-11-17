<?php

declare(strict_types = 1);

namespace Tests\Integration\Services\Input;

use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Services\Input\Payload;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Controllers\StorePayloadController;
use Tests\Fixtures\OpenApi\WorkingFormRequest;
use Tests\TestCase;

/**
 * End-to-end tests that a type-hinted Payload answers Precognition requests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(Payload::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class PayloadPrecognitionTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var array<string, string> Headers that make a request precognitive. */
    private const array PRECOGNITION = ['Precognition' => 'true'];

    /**
     * Set up each test with the toolkit handler and precognitive routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::post('/store-payload', StorePayloadController::class)->middleware(HandlePrecognitiveRequests::class);

        Route::post('/form-request', static fn (WorkingFormRequest $request): JsonResponse => new JsonResponse($request->validated()))
            ->middleware(HandlePrecognitiveRequests::class);
    }

    /**
     * Test that a valid named field succeeds while another is still missing.
     *
     * @return void
     */
    public function testValidNamedFieldSucceedsWhileAnotherIsMissing(): void
    {
        $response = $this->postJson('/store-payload', ['quantity' => 3], self::PRECOGNITION + ['Precognition-Validate-Only' => 'quantity']);

        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');
        $response->assertHeader('Precognition-Success', 'true');
    }

    /**
     * Test that an invalid named field reports only that field.
     *
     * @return void
     */
    public function testInvalidNamedFieldReportsOnlyThatField(): void
    {
        $response = $this->postJson('/store-payload', ['quantity' => 'x'], self::PRECOGNITION + ['Precognition-Validate-Only' => 'quantity']);

        $response->assertStatus(422);
        $response->assertExactJson([
            'message' => 'The quantity field must be an integer.',
            'errors'  => ['quantity' => ['The quantity field must be an integer.']],
        ]);
    }

    /**
     * Test that a named enum field is checked against its enum.
     *
     * @return void
     */
    public function testNamedEnumFieldIsCheckedAgainstItsEnum(): void
    {
        $response = $this->postJson('/store-payload', ['status' => '01'], self::PRECOGNITION + ['Precognition-Validate-Only' => 'status']);

        $response->assertStatus(422);
        $response->assertExactJson([
            'message' => 'The selected status is invalid.',
            'errors'  => ['status' => ['The selected status is invalid.']],
        ]);
    }

    /**
     * Test that a full precognitive request reports every failed field.
     *
     * @return void
     */
    public function testFullPrecognitiveRequestReportsEveryFailedField(): void
    {
        $response = $this->postJson('/store-payload', ['quantity' => 'x'], self::PRECOGNITION);

        $response->assertStatus(422);
        $response->assertHeader('Precognition', 'true');
        $response->assertExactJson([
            'message' => 'The title field is required. (and 1 more error)',
            'errors'  => [
                'title'    => ['The title field is required.'],
                'quantity' => ['The quantity field must be an integer.'],
            ],
        ]);
    }

    /**
     * Test that a valid full precognitive request succeeds without running the
     * controller.
     *
     * @return void
     */
    public function testValidFullPrecognitiveRequestSkipsTheController(): void
    {
        $response = $this->postJson('/store-payload', ['title' => 'Widget', 'quantity' => 3], self::PRECOGNITION);

        $response->assertNoContent();
        $response->assertHeader('Precognition', 'true');
    }

    /**
     * Test that the same route renders the error envelope without Precognition.
     *
     * @return void
     */
    public function testRequestWithoutPrecognitionRendersTheEnvelope(): void
    {
        $response = $this->postJson('/store-payload', ['quantity' => 'x']);

        $response->assertStatus(422);
        $response->assertJsonMissingPath('errors');
        $response->assertJsonPath('error.meta.title', ['The title field is required.']);
        $response->assertJsonPath('error.meta.quantity', ['The quantity field must be an integer.']);
    }

    /**
     * Test that a FormRequest on a precognitive route renders the body
     * Precognition clients read.
     *
     * @return void
     */
    public function testFormRequestOnAPrecognitiveRouteRendersMessageAndErrors(): void
    {
        $response = $this->postJson('/form-request', ['name' => 'Ada'], self::PRECOGNITION);

        $response->assertStatus(422);
        $response->assertExactJson([
            'message' => 'The email field is required.',
            'errors'  => ['email' => ['The email field is required.']],
        ]);
    }
}
