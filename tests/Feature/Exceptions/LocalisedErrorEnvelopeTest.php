<?php

declare(strict_types = 1);

namespace Tests\Feature\Exceptions;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use Tests\Concerns\LocalisesToolkitMessages;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\Fixtures\Middleware\SetRequestLocale;
use Tests\TestCase;

/**
 * Feature tests for error envelopes worded in the request's locale.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiException::class)]
final class LocalisedErrorEnvelopeTest extends TestCase
{
    use LocalisesToolkitMessages, RegistersApiExceptionHandler;

    /** @var string The German conflict title */
    private const string GERMAN_TITLE = 'Konflikt';

    /** @var string The German conflict detail */
    private const string GERMAN_DETAIL = 'Die Anfrage steht im Konflikt mit dem aktuellen Zustand der Ressource';

    /**
     * Publish a German override of the conflict messages and register a route
     * that conflicts in the request's locale.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerApiExceptionHandler();

        $this->overrideToolkitMessages([
            ErrorCode::CONFLICT->getCode() => ['title' => self::GERMAN_TITLE, 'detail' => self::GERMAN_DETAIL],
        ], 'de', 'exceptions');

        Route::middleware(SetRequestLocale::class)->get('/conflict', static function (): never {
            throw new ConflictException;
        });
    }

    /**
     * Provide request locales with the title and detail each renders.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function localeProvider(): iterable
    {
        $english = ['Conflict', 'The request could not be completed due to a conflict with the current state of the resource'];

        yield 'published locale' => ['de', self::GERMAN_TITLE, self::GERMAN_DETAIL];
        yield 'default locale' => ['en', ...$english];
        yield 'locale without messages' => ['fr', ...$english];
    }

    /**
     * Test that the error envelope is worded in the request's locale, falling
     * back to English, under the same code.
     *
     * @param  string  $locale
     * @param  string  $title
     * @param  string  $detail
     * @return void
     */
    #[DataProvider('localeProvider')]
    public function testErrorEnvelopeIsWordedInTheRequestLocale(string $locale, string $title, string $detail): void
    {
        $response = $this->getJson('/conflict', [SetRequestLocale::HEADER => $locale]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 10108);
        $response->assertJsonPath('error.title', $title);
        $response->assertJsonPath('error.detail', $detail);
    }
}
