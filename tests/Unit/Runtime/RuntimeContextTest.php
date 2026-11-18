<?php

declare(strict_types = 1);

namespace Tests\Unit\Runtime;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\ApiToolkit\Runtime\RuntimeContext;
use Tests\TestCase;

/**
 * Tests for the RuntimeContext serving-runtime detector.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RuntimeContext::class)]
final class RuntimeContextTest extends TestCase
{
    /** @var bool|null Whether LARAVEL_OCTANE was set before each test ran. */
    private ?bool $octaneWasSet = null;

    /**
     * Record the pre-test state of the Octane server global.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->octaneWasSet = isset($_SERVER['LARAVEL_OCTANE']);
    }

    /**
     * Restore the Octane server global to its pre-test state.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->octaneWasSet === false) {
            unset($_SERVER['LARAVEL_OCTANE']);
        } elseif ($this->octaneWasSet === true) {
            $_SERVER['LARAVEL_OCTANE'] = 1;
        }

        parent::tearDown();
    }

    /**
     * Test that isServingUnderOctane returns true when the Octane server global
     * is set.
     *
     * @return void
     */
    public function testIsServingUnderOctaneReturnsTrueWhenOctaneServerGlobalSet(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;

        $context = new RuntimeContext;

        self::assertTrue($context->isServingUnderOctane());
    }

    /**
     * Test that isServingUnderOctane returns false when the Octane server
     * global is absent.
     *
     * @return void
     */
    public function testIsServingUnderOctaneReturnsFalseWhenOctaneServerGlobalAbsent(): void
    {
        unset($_SERVER['LARAVEL_OCTANE']);

        $context = new RuntimeContext;

        self::assertFalse($context->isServingUnderOctane());
    }

    /**
     * Test that isServingAsQueueWorker returns true for a non-sync driver.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsTrueForNonSyncDriver(): void
    {
        Config::set('queue.connections.database', ['driver' => 'database']);

        $context = new RuntimeContext;

        self::assertTrue($context->isServingAsQueueWorker('database'));
    }

    /**
     * Test that isServingAsQueueWorker returns false for the sync driver.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsFalseForSyncDriver(): void
    {
        Config::set('queue.connections.sync', ['driver' => 'sync']);

        $context = new RuntimeContext;

        self::assertFalse($context->isServingAsQueueWorker('sync'));
    }

    /**
     * Provide queue drivers with whether their jobs run outside the request.
     *
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function queueDriverProvider(): iterable
    {
        yield 'deferred runs the job in the request after the response' => ['deferred', false];
        yield 'background runs the job in a child process' => ['background', true];
        yield 'failover is worked by a queue worker' => ['failover', true];
    }

    /**
     * Test that isServingAsQueueWorker treats only a driver that runs the job
     * outside the request as a worker.
     *
     * @param  string  $driver
     * @param  bool  $isWorker
     * @return void
     */
    #[DataProvider('queueDriverProvider')]
    public function testIsServingAsQueueWorkerJudgesWhereTheDriverRunsTheJob(string $driver, bool $isWorker): void
    {
        Config::set('queue.connections.probe', ['driver' => $driver]);

        $context = new RuntimeContext;

        self::assertSame($isWorker, $context->isServingAsQueueWorker('probe'));
    }

    /**
     * Test that isServingAsQueueWorker returns false when the connection driver
     * cannot be resolved.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsFalseWhenDriverUnresolved(): void
    {
        $context = new RuntimeContext;

        self::assertFalse($context->isServingAsQueueWorker('nonexistent-connection'));
    }

    /**
     * Test that a connection with an empty or non-string driver is not taken
     * for a queue worker.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerReturnsFalseForAnUnusableDriver(): void
    {
        Config::set('queue.connections.blank', ['driver' => '']);
        Config::set('queue.connections.numeric', ['driver' => 1]);

        $context = new RuntimeContext;

        self::assertFalse($context->isServingAsQueueWorker('blank'));
        self::assertFalse($context->isServingAsQueueWorker('numeric'));
    }

    /**
     * Test that a job without a connection name is judged by the default
     * connection, and by none when the default is not a name.
     *
     * @return void
     */
    public function testIsServingAsQueueWorkerFallsBackToTheDefaultConnection(): void
    {
        Config::set('queue.connections.database', ['driver' => 'database']);
        Config::set('queue.connections.sync', ['driver' => 'sync']);

        $context = new RuntimeContext;

        Config::set('queue.default', 'database');
        self::assertTrue($context->isServingAsQueueWorker(null));

        Config::set('queue.default', 'sync');
        self::assertFalse($context->isServingAsQueueWorker(null));

        Config::set('queue.default', null);
        self::assertFalse($context->isServingAsQueueWorker(null));

        Config::set('queue.connections.1', ['driver' => 'database']);
        Config::set('queue.default', 1);
        self::assertFalse($context->isServingAsQueueWorker(null));
    }

    /**
     * Provide command lines with the command each names.
     *
     * @return iterable<string, array{array<int, string>|null, string|null}>
     */
    public static function argvProvider(): iterable
    {
        yield 'no arguments' => [null, null];
        yield 'no command' => [['artisan'], null];
        yield 'options only' => [['artisan', '-q', '--env', 'production'], null];
        yield 'a bare command' => [['artisan', 'queue:work'], 'queue:work'];
        yield 'leading flags' => [['artisan', '-q', '--no-interaction', 'optimize'], 'optimize'];
        yield 'leading inline option' => [['artisan', '--env=production', 'config:cache'], 'config:cache'];
        yield 'leading separate env value' => [['artisan', '--env', 'optimize', 'route:cache'], 'route:cache'];
        yield 'a later argument' => [['artisan', 'migrate', '--path', 'queue:work'], 'migrate'];
    }

    /**
     * Test that, with no command started in process, the command is the one
     * named on the command line.
     *
     * @param  array<int, string>|null  $argv
     * @param  string|null  $expected
     * @return void
     */
    #[DataProvider('argvProvider')]
    public function testCommandIsReadFromTheCommandLine(?array $argv, ?string $expected): void
    {
        $original = $_SERVER['argv'] ?? null;

        $_SERVER['argv'] = $argv;

        try {
            self::assertSame($expected, (new RuntimeContext)->command());
        } finally {
            $_SERVER['argv'] = $original;
        }
    }

    /**
     * Test that the innermost command started in process wins over the command
     * line until it finishes, and forgetting them restores the command line.
     *
     * @return void
     */
    public function testInnermostStartedCommandWinsUntilItFinishes(): void
    {
        $original = $_SERVER['argv'] ?? null;

        $_SERVER['argv'] = ['artisan', 'app:deploy'];

        $context = new RuntimeContext;

        try {
            RuntimeContext::enterCommand('optimize');
            RuntimeContext::enterCommand('config:cache');

            self::assertSame('config:cache', $context->command());

            RuntimeContext::leaveCommand('config:cache');

            self::assertSame('optimize', $context->command());

            RuntimeContext::enterCommand('route:cache');
            RuntimeContext::reset();

            self::assertSame('app:deploy', $context->command());
        } finally {
            $_SERVER['argv'] = $original;
        }
    }

    /**
     * Test that a command finishing without having started in process leaves
     * the innermost started one in place.
     *
     * @return void
     */
    public function testFinishingACommandThatDidNotStartLeavesTheInnermostOne(): void
    {
        $original = $_SERVER['argv'] ?? null;

        $_SERVER['argv'] = ['artisan', 'app:deploy'];

        $context = new RuntimeContext;

        try {
            RuntimeContext::enterCommand('optimize');
            RuntimeContext::leaveCommand('config:cache');

            self::assertSame('optimize', $context->command());

            RuntimeContext::leaveCommand('optimize');
            RuntimeContext::leaveCommand('optimize');

            self::assertSame('app:deploy', $context->command());
        } finally {
            $_SERVER['argv'] = $original;
        }
    }

    /**
     * Provide commands with whether each serves queue jobs.
     *
     * @return iterable<string, array{string|null, bool}>
     */
    public static function workerCommandProvider(): iterable
    {
        yield 'queue worker' => ['queue:work', true];
        yield 'queue listener' => ['queue:listen', true];
        yield 'horizon worker' => ['horizon:work', true];
        yield 'horizon master' => ['horizon', false];
        yield 'another command' => ['queue:restart', false];
        yield 'no command' => [null, false];
    }

    /**
     * Test that only the commands serving queue jobs count as a queue worker.
     *
     * @param  string|null  $command
     * @param  bool  $expected
     * @return void
     */
    #[DataProvider('workerCommandProvider')]
    public function testIsRunningQueueWorkerForTheWorkerCommandsOnly(?string $command, bool $expected): void
    {
        RuntimeContext::enterCommand($command);

        self::assertSame($expected, (new RuntimeContext)->isRunningQueueWorker());
    }
}
