<?php

declare(strict_types = 1);

namespace Tests\Feature\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Http\Middleware\JsonPrettyPrint;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature tests for the pretty flag reading alike on success and error bodies.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(JsonPrettyPrint::class)]
#[CoversClass(ApiExceptionHandler::class)]
final class PrettyFlagConsistencyTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up a success route and an error route behind the pretty-print
     * middleware.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        Route::middleware(JsonPrettyPrint::class)->group(static function (): void {
            Route::get('/success', static fn (): JsonResponse => new JsonResponse(['data' => ['id' => 1]]));
            Route::get('/error', static fn (): never => throw new ConflictException(['id' => 1]));
        });
    }

    /**
     * Provide each pretty value and whether it asks for indented output.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function prettyValueProvider(): iterable
    {
        yield '1' => ['1', true];
        yield 'true' => ['true', true];
        yield 'on' => ['on', true];
        yield 'yes' => ['yes', true];
        yield '0' => ['0', false];
        yield 'false' => ['false', false];
        yield 'off' => ['off', false];
        yield 'no' => ['no', false];
        yield 'empty' => ['', false];
    }

    /**
     * Test that a success body and an error body make the same pretty or
     * compact decision for each pretty value.
     *
     * @param  string  $value
     * @param  bool  $indented
     * @return void
     */
    #[DataProvider('prettyValueProvider')]
    public function testSuccessAndErrorBodiesReadThePrettyFlagAlike(string $value, bool $indented): void
    {
        $success = $this->getJson('/success?pretty=' . $value)->assertOk()->baseResponse->getContent();
        $error   = $this->getJson('/error?pretty=' . $value)->assertConflict()->baseResponse->getContent();

        self::assertIsString($success);
        self::assertIsString($error);
        self::assertSame($indented, str_contains($success, "\n"));
        self::assertSame($indented, str_contains($error, "\n"));
    }
}
