<?php

declare(strict_types = 1);

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Config;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\ValidateConfigCommand;
use Tests\TestCase;

/**
 * Tests for the ValidateConfigCommand Artisan command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValidateConfigCommand::class)]
final class ValidateConfigCommandTest extends TestCase
{
    /** @var string The command signature. */
    private const string COMMAND = 'api-toolkit:config:validate';

    /** @var string The line printed when every setting is valid. */
    private const string VALID = 'Toolkit settings are valid.';

    /**
     * Test that the shipped configuration passes.
     *
     * @return void
     */
    public function testShippedConfigurationPasses(): void
    {
        $this->runCommand()
            ->expectsOutputToContain(self::VALID)
            ->assertExitCode(0)
            ->run();
    }

    /**
     * Test that a setting the toolkit no longer reads fails, naming it.
     *
     * @return void
     */
    public function testUnknownSettingFails(): void
    {
        Config::set('api-toolkit.lifecycle.octane', true);

        $this->runCommand()
            ->expectsOutputToContain('The api-toolkit.lifecycle.octane setting is not a toolkit setting; remove it or correct its name.')
            ->doesntExpectOutputToContain(self::VALID)
            ->assertExitCode(1)
            ->run();
    }

    /**
     * Test that a setting holding a value it does not accept fails, naming it.
     *
     * @return void
     */
    public function testMalformedSettingFails(): void
    {
        Config::set('api-toolkit.parser.max_limit', '-1');

        $this->runCommand()
            ->expectsOutputToContain('The api-toolkit.parser.max_limit setting must be an integer of at least 0, or null; [\'-1\'] given.')
            ->doesntExpectOutputToContain(self::VALID)
            ->assertExitCode(1)
            ->run();
    }

    /**
     * Run the validate config command.
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
