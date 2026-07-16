<?php

declare(strict_types = 1);

namespace Tests\Feature;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature tests for sensitive-key redaction reaching a real log record.
 *
 * Dispatches a real request carrying sensitive keys to a route that throws a
 * toolkit exception, then reads the record captured on the api-exceptions
 * channel to prove the redaction guarantee holds on the actual logging path -
 * report() -> logApiException() -> the channel - not merely under reflection.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
final class SensitiveDataRedactionTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /**
     * Set up each test with a capturing log channel and a throwing route.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.debug', false);
        Config::set('logging.channels.api-exceptions', [
            'driver'  => 'monolog',
            'handler' => TestHandler::class,
        ]);

        $this->registerApiExceptionHandler();

        Route::post('/api/orders', static function (): never {
            throw new ConflictException;
        });
    }

    /**
     * Test that sensitive request keys are redacted in the logged context while
     * benign keys survive.
     *
     * @return void
     */
    public function testSensitiveKeysAreRedactedInTheLoggedContext(): void
    {
        $response = $this->postJson('/api/orders', [
            'email'     => 'alice@example.com',
            'password'  => 'hunter2',
            'api_token' => 'secret-value',
        ]);

        $response->assertStatus(409);

        $records = $this->capturedRecords();

        self::assertNotEmpty($records);

        /** @var array<string, mixed> $context */
        $context = $records[array_key_last($records)]['context'];

        /** @var array<string, mixed> $data */
        $data = $context['data'];

        self::assertSame('[redacted]', $data['password']);
        self::assertSame('[redacted]', $data['api_token']);
        self::assertSame('alice@example.com', $data['email']);
    }

    /**
     * Read the records captured on the api-exceptions channel.
     *
     * @return array<int, \Monolog\LogRecord>
     */
    private function capturedRecords(): array
    {
        $channel = Log::channel('api-exceptions');

        assert($channel instanceof Logger);

        $monolog = $channel->getLogger();

        assert($monolog instanceof MonologLogger);

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler->getRecords();
            }
        }

        return [];
    }
}
