<?php

namespace Tests\Integration\Console;

use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\Console\LintRoutesCommand;
use Tests\TestCase;

/**
 * Integration tests for the LintRoutesCommand Artisan command.
 *
 * Registers fixture routes on the booted framework router and drives the
 * command via Testbench's artisan() helper, asserting exit codes and output.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LintRoutesCommand::class)]
class LintRoutesCommandTest extends TestCase
{
    /** @var string The command signature under test. */
    private const string COMMAND = 'api-toolkit:lint-routes';

    /** @var array<int, string> Default verb denylist used across tests. */
    private const VERB_DENYLIST = [
        'get', 'list', 'create', 'add', 'update', 'edit', 'delete',
        'remove', 'cancel', 'login', 'logout', 'search', 'fetch',
    ];

    /**
     * Test that the command exits non-zero when an error-severity violation is present.
     *
     * @return void
     */
    public function testExitsNonZeroWhenErrorViolationPresent(): void
    {
        $this->seedConfig();

        $this->getRouter()->get('getUsers', fn () => [])->name('get-users');

        $this->runCommand()->assertExitCode(1);
    }

    /**
     * Test that the command exits zero when the route table is clean.
     *
     * @return void
     */
    public function testExitsZeroWhenTableIsClean(): void
    {
        $this->seedConfig();

        $this->getRouter()->get('users', fn () => [])->name('users.index');

        $this->runCommand()->assertExitCode(0);
    }

    /**
     * Test that the command prints error findings in the output before failing.
     *
     * @return void
     */
    public function testPrintsFindingsBeforeFailing(): void
    {
        $this->seedConfig();

        $this->getRouter()->get('getUsers', fn () => [])->name('get-users');

        $this->runCommand()
            ->expectsOutputToContain('Route linting errors')
            ->assertExitCode(1);
    }

    /**
     * Test that a misconfigured waiver (entry without a reason) exits non-zero with an error message.
     *
     * @return void
     */
    public function testMisconfiguredWaiverFailsTheCommand(): void
    {
        $this->seedConfig([
            ['match' => 'legacy.route'],
        ]);

        $this->getRouter()->get('users', fn () => [])->name('users.index');

        $this->runCommand()
            ->expectsOutputToContain('missing a required reason')
            ->assertExitCode(1);
    }

    /**
     * Test that a route table with only warning-severity findings exits zero.
     *
     * @return void
     */
    public function testWarningOnlyTableExitsZero(): void
    {
        $this->seedConfig();

        // A named route whose name does not follow {resource}.{action} — triggers R8 (warning)
        $this->getRouter()->get('users', fn () => [])->name('users.getAll');

        $this->runCommand()->assertExitCode(0);
    }

    /**
     * Test that a route whose only error is inline-suppressed exits zero, and
     * that the unused suppression (the route is otherwise clean) is surfaced in
     * the output but does not change the exit code.
     *
     * @return void
     */
    public function testInlineSuppressedRouteExitsZeroAndSurfacesStaleSuppression(): void
    {
        $this->seedConfig();

        // /users is a clean route; the inline suppression for R1 fires on nothing
        $this->getRouter()->get('users', fn () => [])
            ->name('users.index')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'Unnecessary waiver added for demonstration.');

        $this->runCommand()
            ->expectsOutputToContain('Stale waivers / unused suppressions')
            ->expectsOutputToContain('suppressed nothing')
            ->assertExitCode(0);
    }

    /**
     * Test that a route whose only error is fully suppressed by an inline
     * suppression (all rules) exits zero.
     *
     * @return void
     */
    public function testFullyInlineSuppressedRouteExitsZero(): void
    {
        $this->seedConfig();

        // /getUsers triggers R1; inline suppression covers all rules
        $this->getRouter()->get('getUsers', fn () => [])
            ->name('get-users')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint([], 'All rules suppressed for this legacy route.');

        $this->runCommand()->assertExitCode(0);
    }

    /**
     * Run the lint-routes command.
     *
     * @return \Illuminate\Testing\PendingCommand
     */
    private function runCommand(): PendingCommand
    {
        $command = $this->artisan(self::COMMAND);

        assert($command instanceof PendingCommand);

        return $command;
    }

    /**
     * Seed the route_linting config section.
     *
     * @param  array<int, array<string, string>>  $exemptions
     * @return void
     */
    private function seedConfig(array $exemptions = []): void
    {
        config()->set('api-toolkit.route_linting.verb_denylist', self::VERB_DENYLIST);
        config()->set('api-toolkit.route_linting.remediation_hints', []);
        config()->set('api-toolkit.route_linting.exemptions', $exemptions);
        config()->set('api-toolkit.route_linting.uncountables', []);
    }

    /**
     * Get the router instance bound to the booted application.
     *
     * @return \Illuminate\Routing\Router
     */
    private function getRouter(): Router
    {
        assert($this->app !== null);

        /** @var \Illuminate\Routing\Router */
        return $this->app->make('router');
    }
}
