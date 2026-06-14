<?php

namespace Tests\Unit\RouteLinting;

use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\RouteLintReport;
use SineMacula\ApiToolkit\RouteLinting\Severity;
use SineMacula\ApiToolkit\RouteLinting\Violation;
use Tests\TestCase;

/**
 * Tests for the RouteLintReport verdict aggregate.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RouteLintReport::class)]
class RouteLintReportTest extends TestCase
{
    /**
     * Test that errors() returns only ERROR-severity violations and warnings()
     * returns only WARNING-severity violations.
     *
     * @return void
     */
    public function testErrorsReturnsOnlyErrorSeverityViolations(): void
    {
        // Arrange
        $report = new RouteLintReport;

        $error   = new Violation('R1', Severity::ERROR, 'GET users', 'getUsers', null);
        $warning = new Violation('R8', Severity::WARNING, 'GET users', 'users.getAll', null);

        $report->addViolation($error);
        $report->addViolation($warning);

        // Act
        $errors   = $report->errors();
        $warnings = $report->warnings();

        // Assert
        static::assertCount(1, $errors);
        static::assertSame(Severity::ERROR, $errors[0]->severity);
        static::assertSame('R1', $errors[0]->ruleId);

        static::assertCount(1, $warnings);
        static::assertSame(Severity::WARNING, $warnings[0]->severity);
        static::assertSame('R8', $warnings[0]->ruleId);
    }

    /**
     * Test that findings are returned in a deterministic total order regardless
     * of insertion order (NFR-01).
     *
     * Two report instances seeded with the same violations in different orders
     * must produce byte-identical sorted arrays.
     *
     * @return void
     */
    public function testFindingsAreDeterministicallyOrdered(): void
    {
        // Arrange — three violations that differ on all three sort keys
        $alpha = new Violation('R1', Severity::ERROR, 'GET,HEAD users', 'getUsers', null);
        $beta  = new Violation('R2', Severity::ERROR, 'GET,HEAD users', 'UserProfiles', null);
        $gamma = new Violation('R1', Severity::ERROR, 'GET articles', 'getArticles', null);

        // Report A: inserted in alpha, beta, gamma order
        $reportA = new RouteLintReport;
        $reportA->addViolation($alpha);
        $reportA->addViolation($beta);
        $reportA->addViolation($gamma);

        // Report B: inserted in shuffled gamma, alpha, beta order
        $reportB = new RouteLintReport;
        $reportB->addViolation($gamma);
        $reportB->addViolation($alpha);
        $reportB->addViolation($beta);

        // Act
        $errorsA = $reportA->errors();
        $errorsB = $reportB->errors();

        // Assert — identical count and identical identity/ruleId/surface per position
        static::assertCount(3, $errorsA);
        static::assertCount(3, $errorsB);

        foreach ($errorsA as $index => $violation) {
            static::assertSame($violation->routeIdentity, $errorsB[$index]->routeIdentity);
            static::assertSame($violation->ruleId, $errorsB[$index]->ruleId);
            static::assertSame($violation->offendingSurface, $errorsB[$index]->offendingSurface);
        }

        // Assert the concrete order: gamma sorts before alpha/beta (route identity 'GET articles' < 'GET,HEAD users')
        static::assertSame('GET articles', $errorsA[0]->routeIdentity);
        // Within 'GET,HEAD users': R1 before R2
        static::assertSame('R1', $errorsA[1]->ruleId);
        static::assertSame('R2', $errorsA[2]->ruleId);
    }

    /**
     * Test that a report containing only warnings and stale waivers returns
     * hasErrors() === false.
     *
     * @return void
     */
    public function testHasErrorsIsFalseForWarningOnlyReport(): void
    {
        // Arrange
        $report = new RouteLintReport;

        $report->addViolation(new Violation('R8', Severity::WARNING, 'GET users', 'users.getAll', null));
        $report->addViolation(new Violation('R11', Severity::WARNING, 'GET a/b/c/d', 'a/b/c/d', null));
        $report->addStaleWaiver('users.legacy');

        // Act & Assert
        static::assertFalse($report->hasErrors());
        static::assertCount(2, $report->warnings());
        static::assertEmpty($report->errors());
    }

    /**
     * Test that staleWaivers() returns stale entries sorted ascending regardless
     * of insertion order.
     *
     * @return void
     */
    public function testStaleWaiversAreSorted(): void
    {
        // Arrange
        $report = new RouteLintReport;

        $report->addStaleWaiver('users.legacy');
        $report->addStaleWaiver('articles.old');
        $report->addStaleWaiver('beta.route');

        // Act
        $stale = $report->staleWaivers();

        // Assert
        static::assertSame(['articles.old', 'beta.route', 'users.legacy'], $stale);
    }

    /**
     * Test that a freshly constructed report has no errors, no warnings, no
     * stale waivers, and hasErrors() returns false.
     *
     * @return void
     */
    public function testEmptyReportHasNoErrors(): void
    {
        // Arrange
        $report = new RouteLintReport;

        // Act & Assert
        static::assertFalse($report->hasErrors());
        static::assertSame([], $report->errors());
        static::assertSame([], $report->warnings());
        static::assertSame([], $report->staleWaivers());
    }
}
