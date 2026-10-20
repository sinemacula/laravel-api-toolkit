<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ClearResourcesCommand;
use Tests\Fixtures\Models\User;
use Tests\Fixtures\Resources\UserResource;
use Tests\TestCase;

/**
 * Tests for the ClearResourcesCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ClearResourcesCommand::class)]
final class ClearResourcesCommandTest extends TestCase
{
    /** @var string The command signature. */
    private const string COMMAND = 'api-toolkit:resources:clear';

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedBootstrapPath();
    }

    /**
     * Test that the command removes the compiled map.
     *
     * @return void
     */
    public function testCommandRemovesTheCompiledMap(): void
    {
        $path = $this->writeCompiledMap([User::class => UserResource::class]);

        $this->pendingArtisan(self::COMMAND)
            ->expectsOutputToContain('Cached API resources cleared successfully.')
            ->assertExitCode(0)
            ->run();

        self::assertFileDoesNotExist($path);
    }

    /**
     * Test that the command succeeds when there is no compiled map to remove.
     *
     * @return void
     */
    public function testCommandSucceedsWithoutACompiledMap(): void
    {
        $this->pendingArtisan(self::COMMAND)
            ->expectsOutputToContain('Cached API resources cleared successfully.')
            ->assertExitCode(0)
            ->run();

        assert($this->app !== null);

        self::assertFileDoesNotExist($this->app->bootstrapPath('cache/api-toolkit-resources.php'));
    }
}
