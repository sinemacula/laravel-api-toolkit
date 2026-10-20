<?php

declare(strict_types = 1);

namespace Tests\Feature\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Exceptions\ApiExceptionHandler;
use SineMacula\ApiToolkit\Exceptions\ConflictException;
use SineMacula\ApiToolkit\Exceptions\UnhandledException;
use Tests\Concerns\RegistersApiExceptionHandler;
use Tests\TestCase;

/**
 * Feature tests for which exceptions reach the application log.
 *
 * Dispatches real requests through the kernel with the default log channel
 * captured, proving that handled exceptions never reach a reporter or the log,
 * that unhandled exceptions are reported as themselves, and that the request
 * input never reaches the logged record.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ApiExceptionHandler::class)]
final class ExceptionReportingTest extends TestCase
{
    use RegistersApiExceptionHandler;

    /** @var string The capturing log channel made the default for each test. */
    private const string CHANNEL = 'exception-reporting-capture';

    /** @var string The message of the unhandled exception the route throws. */
    private const string UNHANDLED_MESSAGE = 'Order storage is unavailable';

    /**
     * Set up each test with a capturing default log channel and failing routes.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.debug', false);
        Config::set('api-toolkit.exceptions.include_debug_info', false);
        Config::set('logging.channels.' . self::CHANNEL, [
            'driver'  => 'monolog',
            'handler' => TestHandler::class,
            'level'   => 'debug',
        ]);
        Config::set('logging.default', self::CHANNEL);

        Route::post('/unhandled', static function (): never {
            throw new \RuntimeException(self::UNHANDLED_MESSAGE);
        });

        Route::post('/handled', static function (): never {
            throw new ConflictException;
        });

        Route::get('/hand-thrown', static function (): never {
            throw new UnhandledException;
        });
    }

    /**
     * Test that an unhandled exception is reported once as itself, with none of
     * the request input in the logged record, and rendered as the generic 500.
     *
     * @return void
     */
    public function testUnhandledExceptionIsReportedWithoutRequestInput(): void
    {
        $this->registerApiExceptionHandler();

        $response = $this->postJson('/unhandled', [
            'email'    => 'alice@example.com',
            'name'     => 'Alice Example',
            'phone'    => '+44 7700 900123',
            'password' => 'hunter2',
        ]);

        $response->assertStatus(500);
        self::assertSame([
            'error' => [
                'status' => 500,
                'code'   => UnhandledException::getInternalErrorCode(),
                'title'  => 'Unknown Error',
                'detail' => 'Oh no! Something has gone wrong!',
            ],
        ], $response->json());

        $records = $this->capturedRecords();

        self::assertCount(1, $records);

        $record = $records[0];

        self::assertSame(Level::Error, $record->level);
        self::assertSame(self::UNHANDLED_MESSAGE, $record->message);
        self::assertSame(['exception'], array_keys($record->context));
        self::assertInstanceOf(\RuntimeException::class, $record->context['exception']);
        self::assertSame(self::UNHANDLED_MESSAGE, $record->context['exception']->getMessage());
        self::assertSame([], $record->extra);
    }

    /**
     * Test that a handled exception reaches neither a reportable registered
     * before the toolkit handler nor the log.
     *
     * @return void
     */
    public function testHandledExceptionIsNeitherReportedNorLogged(): void
    {
        $captured = [];

        $exceptions = new Exceptions(app(ExceptionHandlerContract::class));

        $exceptions->report(static function (\Throwable $exception) use (&$captured): void {
            $captured[] = $exception;
        });

        ApiExceptionHandler::handles($exceptions);

        $this->postJson('/handled')->assertStatus(409);

        self::assertSame([], $captured);
        self::assertSame([], $this->capturedRecords());
    }

    /**
     * Test that an unhandled exception thrown by hand is still reported.
     *
     * @return void
     */
    public function testHandThrownUnhandledExceptionIsReported(): void
    {
        $this->registerApiExceptionHandler();

        $this->getJson('/hand-thrown')->assertStatus(500);

        $records = $this->capturedRecords();

        self::assertCount(1, $records);
        self::assertInstanceOf(UnhandledException::class, $records[0]->context['exception']);
    }

    /**
     * Read the records captured on the default log channel.
     *
     * @return array<int, \Monolog\LogRecord>
     */
    private function capturedRecords(): array
    {
        $channel = Log::channel(self::CHANNEL);

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
