<?php

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\FlushCachesCommand;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use Tests\TestCase;

/**
 * Tests for the FlushCachesCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(FlushCachesCommand::class)]
class FlushCachesCommandTest extends TestCase
{
    /** @var string The command signature. */
    private const string COMMAND = 'api-toolkit:flush-caches';

    /**
     * Test that the command flushes all caches and outputs a confirmation
     * message.
     *
     * @return void
     */
    public function testHandleFlushesAllCachesAndOutputsConfirmation(): void
    {
        Event::fake();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('All API toolkit caches have been flushed.');
        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that the command returns a success exit code.
     *
     * @return void
     */
    public function testHandleReturnsSuccessExitCode(): void
    {
        Event::fake();

        $this->artisan(self::COMMAND)
            ->assertExitCode(0);    }
}
