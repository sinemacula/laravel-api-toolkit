<?php

namespace Tests\Unit\RouteLinting\Output;

use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Output\ConsoleLintReporter;
use SineMacula\ApiToolkit\RouteLinting\RouteLintReport;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Tests for the ConsoleLintReporter adapter.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ConsoleLintReporter::class)]
class ConsoleLintReporterTest extends TestCase
{
    /** @var \Symfony\Component\Console\Output\BufferedOutput */
    private BufferedOutput $buffer;

    /** @var \SineMacula\ApiToolkit\RouteLinting\Output\ConsoleLintReporter */
    private ConsoleLintReporter $reporter;

    /**
     * Set up a buffered output sink and a fresh reporter for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->buffer   = new BufferedOutput;
        $this->reporter = new ConsoleLintReporter(
            new OutputStyle(new ArrayInput([]), $this->buffer),
        );
    }

    /**
     * Test that an error violation with a remediation hint renders a line
     * containing the rule id, route identity, offending surface, and hint.
     *
     * @return void
     */
    public function testRendersErrorFindingsWithHint(): void
    {
        // Arrange
        $report    = new RouteLintReport;
        $violation = new Violation(
            ruleId: 'R1',
            severity: Severity::ERROR,
            routeIdentity: 'GET /get-users',
            offendingSurface: 'get',
            remediationHint: 'use a noun-based path instead',
        );
        $report->addViolation($violation);

        // Act
        $this->reporter->report($report);
        $output = $this->buffer->fetch();

        // Assert — error header and violation line are present
        static::assertStringContainsString('Route linting errors', $output);
        static::assertStringContainsString('[R1]', $output);
        static::assertStringContainsString('GET /get-users', $output);
        static::assertStringContainsString('get', $output);
        static::assertStringContainsString('use a noun-based path instead', $output);
    }

    /**
     * Test that a warning violation renders a line under the warning header
     * containing the rule id and route identity.
     *
     * @return void
     */
    public function testRendersWarningFindings(): void
    {
        // Arrange
        $report    = new RouteLintReport;
        $violation = new Violation(
            ruleId: 'R8',
            severity: Severity::WARNING,
            routeIdentity: 'GET users',
            offendingSurface: 'users.getAll',
            remediationHint: null,
        );
        $report->addViolation($violation);

        // Act
        $this->reporter->report($report);
        $output = $this->buffer->fetch();

        // Assert — warning header and violation line are present; no error header
        static::assertStringContainsString('Route linting warnings', $output);
        static::assertStringContainsString('[R8]', $output);
        static::assertStringContainsString('GET users', $output);
        static::assertStringContainsString('users.getAll', $output);
        static::assertStringNotContainsString('Route linting errors', $output);
    }

    /**
     * Test that a stale-waiver entry renders a line naming the allowlist key
     * under the stale-waivers header.
     *
     * @return void
     */
    public function testRendersStaleWaivers(): void
    {
        // Arrange
        $report = new RouteLintReport;
        $report->addStaleWaiver('users.legacy');

        // Act
        $this->reporter->report($report);
        $output = $this->buffer->fetch();

        // Assert — stale-waivers header and entry key are present
        static::assertStringContainsString('Stale waivers / unused suppressions', $output);
        static::assertStringContainsString('users.legacy', $output);
    }

    /**
     * Test that a report with no errors, warnings, or stale waivers renders
     * a success line and no finding headers.
     *
     * @return void
     */
    public function testCleanReportRendersSuccessLine(): void
    {
        // Arrange
        $report = new RouteLintReport;

        // Act
        $this->reporter->report($report);
        $output = $this->buffer->fetch();

        // Assert — success message present; no finding headers
        static::assertStringContainsString('All routes conform to the RESTful conventions.', $output);
        static::assertStringNotContainsString('Route linting errors', $output);
        static::assertStringNotContainsString('Route linting warnings', $output);
        static::assertStringNotContainsString('Stale waivers / unused suppressions', $output);
    }

    /**
     * Test that an error violation with no remediation hint renders a line
     * without the hint segment.
     *
     * @return void
     */
    public function testRendersErrorFindingsWithoutHint(): void
    {
        // Arrange
        $report    = new RouteLintReport;
        $violation = new Violation(
            ruleId: 'R2',
            severity: Severity::ERROR,
            routeIdentity: 'GET userProfiles',
            offendingSurface: 'userProfiles',
            remediationHint: null,
        );
        $report->addViolation($violation);

        // Act
        $this->reporter->report($report);
        $output = $this->buffer->fetch();

        // Assert — violation line is present without a hint segment
        static::assertStringContainsString('[R2]', $output);
        static::assertStringContainsString('userProfiles', $output);
        static::assertStringNotContainsString('Hint:', $output);
    }

    /**
     * Test that empty error and stale sections are skipped when only warnings exist.
     *
     * @return void
     */
    public function testSkipsEmptySections(): void
    {
        // Arrange — warning only; no errors and no stale waivers
        $report    = new RouteLintReport;
        $violation = new Violation(
            ruleId: 'R11',
            severity: Severity::WARNING,
            routeIdentity: 'GET a/b/c/d',
            offendingSurface: 'a/b/c/d',
            remediationHint: null,
        );
        $report->addViolation($violation);

        // Act
        $this->reporter->report($report);
        $output = $this->buffer->fetch();

        // Assert — only the warning section header appears
        static::assertStringContainsString('Route linting warnings', $output);
        static::assertStringNotContainsString('Route linting errors', $output);
        static::assertStringNotContainsString('Stale waivers / unused suppressions', $output);
    }
}
