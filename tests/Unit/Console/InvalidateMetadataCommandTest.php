<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Cache\MetadataCacheWriter;
use SineMacula\ApiToolkit\Cache\MetadataGeneration;
use SineMacula\ApiToolkit\Cache\MetadataKeyRegistry;
use SineMacula\ApiToolkit\Console\InvalidateMetadataCommand;
use SineMacula\ApiToolkit\Enums\CacheKeys;
use SineMacula\ApiToolkit\Events\CacheFlushed;
use Tests\TestCase;

/**
 * Tests for the InvalidateMetadataCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(InvalidateMetadataCommand::class)]
final class InvalidateMetadataCommandTest extends TestCase
{
    /** @var string The command signature. */
    private const string COMMAND = 'api-toolkit:invalidate-metadata';

    /**
     * Test that the command replaces the stored generation and reports it.
     *
     * @return void
     */
    public function testCommandReplacesTheGeneration(): void
    {
        $before = (new MetadataGeneration)->current();

        $this->runCommand()
            ->expectsOutputToContain('Toolkit metadata invalidated.')
            ->assertExitCode(0)
            ->run();

        $after = Cache::get(CacheKeys::METADATA_GENERATION->resolveKey());

        self::assertIsString($after);
        self::assertNotSame($before, $after);
    }

    /**
     * Test that metadata written by an earlier process is served to no process
     * after the command runs, though the command never touched its key.
     *
     * @return void
     */
    public function testCommandRetiresMetadataAnEarlierProcessWrote(): void
    {
        $earlier = new MetadataCacheWriter(new MetadataKeyRegistry, new MetadataGeneration);
        $earlier->rememberMetadataForever('deployed-metadata', fn (): string => 'stale');

        $this->runCommand()->assertExitCode(0)->run();

        $later = new MetadataCacheWriter(new MetadataKeyRegistry, new MetadataGeneration);

        self::assertNull($later->readMetadata('deployed-metadata'));
    }

    /**
     * Test that the command also flushes the process it runs in.
     *
     * @return void
     */
    public function testCommandFlushesItsOwnProcess(): void
    {
        Event::fake([CacheFlushed::class]);

        $this->runCommand()->assertExitCode(0)->run();

        Event::assertDispatched(CacheFlushed::class);
    }

    /**
     * Test that the command fails, and says why, when the store rejects the new
     * generation.
     *
     * @return void
     */
    public function testCommandFailsWhenTheStoreRejectsTheGeneration(): void
    {
        $this->useRejectingCacheStore();

        $this->runCommand()
            ->expectsOutputToContain('The cache store rejected the new metadata generation, so the cached metadata was not invalidated.')
            ->doesntExpectOutputToContain('Toolkit metadata invalidated.')
            ->assertExitCode(1)
            ->run();
    }

    /**
     * Run the invalidate metadata command.
     *
     * @return \Illuminate\Testing\PendingCommand
     */
    private function runCommand(): PendingCommand
    {
        $command = $this->artisan(self::COMMAND);

        assert($command instanceof PendingCommand);

        return $command;
    }
}
