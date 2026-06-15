<?php

namespace Tests\Integration\RouteLinting;

use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\ApiToolkit\RouteLinting\Configuration\ConfigRuleConfiguration;
use SineMacula\ApiToolkit\RouteLinting\Inflection\FrameworkInflector;
use SineMacula\ApiToolkit\RouteLinting\LintRoutes;
use SineMacula\ApiToolkit\RouteLinting\RouteLintEngine;
use SineMacula\ApiToolkit\RouteLinting\RouteLintReport;
use SineMacula\ApiToolkit\RouteLinting\Rules\ApiResourceAlignmentRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\KebabCaseRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\LowercaseRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\NestingDepthRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\PluralCollectionsRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\RouteNameRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\SlashSanityRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\StandardMethodsRule;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\SegmentNormaliser;
use SineMacula\ApiToolkit\RouteLinting\Rules\Support\VerbDenylist;
use SineMacula\ApiToolkit\RouteLinting\Rules\VerbInPathRule;
use SineMacula\ApiToolkit\RouteLinting\Sources\RouterRouteSource;
use Tests\TestCase;

/**
 * End-to-end integration tests for the LintRoutes use case.
 *
 * Drives the use case against a fixture route table registered on the booted
 * framework router. Config is seeded via `config()->set()` so each test can
 * control the verb denylist, exemptions, and uncountables independently.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LintRoutes::class)]
class LintRoutesTest extends TestCase
{
    /**
     * Default verb denylist that covers the fixture offenders used by most tests.
     *
     * @var array<int, string>
     */
    private const VERB_DENYLIST = [
        'get', 'list', 'create', 'add', 'update', 'edit', 'delete',
        'remove', 'cancel', 'login', 'logout', 'search', 'fetch',
        'transfer', 'check', 'process', 'submit',
    ];

    /**
     * Test that the five common offenders are flagged by defaults and /users is clean (TAC-15-01).
     *
     * Fixture routes: /getUsers, /users/create, /order/{id}/cancel, /login,
     * /userProfiles each produce at least one violation; /users produces none.
     *
     * @return void
     */
    public function testFlagsCommonOffendersWithDefaults(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('getUsers', fn () => [])->name('get-users');
        $router->post('users/create', fn () => [])->name('users.create-action');
        $router->post('order/{id}/cancel', fn () => [])->name('order.cancel');
        $router->post('login', fn () => [])->name('auth.login');
        $router->get('userProfiles', fn () => [])->name('user-profiles');
        $router->get('users', fn () => [])->name('users.index');

        $report = $this->buildUseCase($router)->lint();

        $offendingIdentities = $this->collectOffendingIdentities($report);

        static::assertNotEmpty($report->errors(), 'Expected at least one error-severity violation.');

        // Each offending route must have at least one finding
        static::assertTrue($this->hasViolationForUri($report, 'getUsers'), '/getUsers should be flagged');
        static::assertTrue($this->hasViolationForUri($report, 'users/create'), '/users/create should be flagged');
        static::assertTrue($this->hasViolationForUri($report, 'order/{id}/cancel'), '/order/{id}/cancel should be flagged');
        static::assertTrue($this->hasViolationForUri($report, 'login'), '/login should be flagged');
        static::assertTrue($this->hasViolationForUri($report, 'userProfiles'), '/userProfiles should be flagged');

        // The control route must be clean
        static::assertNotContains('GET,HEAD users users.index', $offendingIdentities, '/users should be clean');
    }

    /**
     * Test that an exempted violating route has its violation suppressed and is not stale (TAC-15-02).
     *
     * @return void
     */
    public function testExemptedViolationIsSuppressed(): void
    {
        $this->seedDefaultConfig([
            [
                'match'  => 'login',
                'reason' => 'Legacy endpoint kept for backward compatibility.',
            ],
        ]);

        $router = $this->getRouter();
        $router->post('login', fn () => [])->name('auth.login');

        $report = $this->buildUseCase($router)->lint();

        // The /login route is violating but covered by an allowlist entry — no errors expected
        static::assertSame([], $report->errors(), 'Violation for exempted route should be suppressed.');

        // The allowlist entry matched a live route — no stale waivers
        static::assertSame([], $report->staleWaivers(), 'Matched entry must not appear as stale.');
    }

    /**
     * Test that an allowlist entry matching no live route appears in staleWaivers() (TAC-15-03).
     *
     * @return void
     */
    public function testStaleWaiverIsReported(): void
    {
        $this->seedDefaultConfig([
            [
                'match'  => 'no-such-route',
                'reason' => 'Was needed for the old API; route no longer exists.',
            ],
        ]);

        $router = $this->getRouter();
        $router->get('users', fn () => [])->name('users.index');

        $report = $this->buildUseCase($router)->lint();

        static::assertContains('no-such-route', $report->staleWaivers(), 'Unmatched entry must be reported as stale.');
    }

    /**
     * Test that removing a verb from the denylist clears the R1 finding without creating an exemption (TAC-15-04).
     *
     * Removing `transfer` from the denylist means /transfers is clean for R1;
     * no allowlist entry is configured, so staleWaivers() stays empty.
     *
     * @return void
     */
    public function testHomographRemovalClearsVerbFindingWithoutExemption(): void
    {
        // Denylist WITHOUT 'transfer' — it was removed as a homograph
        $denylistWithoutTransfer = array_values(array_filter(
            self::VERB_DENYLIST,
            fn (string $v): bool => $v !== 'transfer',
        ));

        $this->seedDefaultConfig([], $denylistWithoutTransfer);

        $router = $this->getRouter();
        $router->get('transfers', fn () => [])->name('transfers.index');

        $report = $this->buildUseCase($router)->lint();

        // No R1 violation for /transfers because 'transfer' was removed from the denylist
        $r1Violations = array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1');

        static::assertSame([], array_values($r1Violations), 'No R1 violation expected after homograph removal.');

        // No exemption entry was created — allowlist stays empty, no stale waivers
        static::assertSame([], $report->staleWaivers(), 'No exemption entry should be stale when using denylist tuning.');
    }

    /**
     * Test that an inline suppression on a route drops exactly that rule's error while
     * a different rule on the same route still reports (per-rule inline suppression).
     *
     * Route /getUsers triggers R1 (verb in path) and R2 (camelCase).
     * Suppressing only R1 inline leaves R2 still reported.
     *
     * @return void
     */
    public function testInlineSuppressionDropsOnlyTargetedRule(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('getUsers', fn () => [])
            ->name('get-users')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'Verb in path is intentional for this legacy endpoint.');

        $report = $this->buildUseCase($router)->lint();

        // R1 is suppressed inline; it must not appear in the report
        $r1Violations = array_values(array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1'));
        static::assertSame([], $r1Violations, 'R1 must be suppressed by the inline suppression.');

        // R2 (camelCase) is NOT covered by the inline suppression and must still be reported
        $r2Violations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId === 'R2',
        ));
        static::assertNotEmpty($r2Violations, 'R2 must still be reported on the same route.');
    }

    /**
     * Test that a config allowlist entry with an explicit rules list suppresses
     * only the listed rules (per-rule config suppression).
     *
     * @return void
     */
    public function testConfigExemptionWithRulesIsPerRule(): void
    {
        $this->seedDefaultConfig([
            [
                'match'  => 'login',
                'reason' => 'Legacy auth endpoint; R1 only waived.',
                'rules'  => ['R1'],
            ],
        ]);

        $router = $this->getRouter();
        $router->post('login', fn () => [])->name('auth.login');

        $report = $this->buildUseCase($router)->lint();

        // R1 is waived by the config entry; it must not appear in errors
        $r1Violations = array_values(array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1'));
        static::assertSame([], $r1Violations, 'R1 must be suppressed by the config exemption.');

        // Other rules on /login are NOT waived and must still appear
        $otherViolations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId !== 'R1',
        ));
        static::assertNotEmpty($otherViolations, 'Non-R1 violations on /login must still be reported.');
    }

    /**
     * Test that an inline suppression that suppresses no violation produces a
     * stale entry on the report (unused inline suppression detection).
     *
     * Route /users is clean; the inline suppression for R1 fires on nothing.
     *
     * @return void
     */
    public function testUnusedInlineSuppressionIsReportedAsStale(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('users', fn () => [])
            ->name('users.index')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'Pre-emptive suppression that turns out to be unnecessary.');

        $report = $this->buildUseCase($router)->lint();

        // The route is clean (no R1 violation), so the inline suppression is stale
        $staleWaivers = $report->staleWaivers();
        static::assertNotEmpty($staleWaivers, 'An unused inline suppression must appear as a stale entry.');

        $staleString = implode(' ', $staleWaivers);
        static::assertStringContainsString('suppressed nothing', $staleString);
        static::assertStringContainsString('Pre-emptive suppression that turns out to be unnecessary.', $staleString);
    }

    /**
     * Test that a config entry without a rules list suppresses all violations on
     * its route (backward-compatibility: omitting rules means all rules).
     *
     * @return void
     */
    public function testConfigExemptionWithoutRulesSuppressesAllViolations(): void
    {
        $this->seedDefaultConfig([
            [
                'match'  => 'login',
                'reason' => 'Legacy endpoint kept for backward compatibility.',
            ],
        ]);

        $router = $this->getRouter();
        $router->post('login', fn () => [])->name('auth.login');

        $report = $this->buildUseCase($router)->lint();

        // All violations on /login are suppressed; no errors or warnings expected for that route
        $loginViolations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => str_contains($v->routeIdentity, 'login'),
        ));
        static::assertSame([], $loginViolations, 'All violations on an all-rules-waived route must be suppressed.');

        // The allowlist entry matched a live route and suppressed violations — not stale
        static::assertSame([], $report->staleWaivers(), 'A used config entry must not appear as stale.');
    }

    /**
     * Test that two runs over the same route table and config produce byte-identical verdicts (TAC-15-05 / NFR-01).
     *
     * @return void
     */
    public function testRepeatableVerdictAcrossTwoRuns(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('getUsers', fn () => [])->name('get-users');
        $router->post('users/create', fn () => [])->name('users.create-action');
        $router->get('users', fn () => [])->name('users.index');

        $useCase = $this->buildUseCase($router);

        $firstReport  = $useCase->lint();
        $secondReport = $useCase->lint();

        static::assertSame(
            $this->serialiseReport($firstReport),
            $this->serialiseReport($secondReport),
            'Two runs over the same inputs must produce identical verdicts (NFR-01).',
        );
    }

    /**
     * Test that the observe loop runs for every descriptor so a matched-but-unused
     * config exemption (route exists and is clean) is reported as a stale unused entry.
     *
     * Mutants killed: #13 (Foreach_ on observe loop), #14 (MethodCallRemoval of observe()),
     * #16 (Foreach_ on allowlist->unused() loop).
     *
     * If the observe loop is skipped the entry is never marked as matched, so it
     * appears in unmatched() instead of unused(). If the unused() loop is skipped
     * the entry is silently dropped and no stale waiver appears at all. Asserting
     * the specific "suppressed nothing" text distinguishes all three cases.
     *
     * @return void
     */
    public function testMatchedButUnusedConfigEntryIsReportedAsStaleUnused(): void
    {
        // The /users route is clean (no violations); the exemption for 'users.index'
        // matches it via route name but suppresses nothing, because no violation fires.
        $this->seedDefaultConfig([
            [
                'match'  => 'users.index',
                'reason' => 'Pre-emptive waiver that turns out to serve no purpose.',
            ],
        ]);

        $router = $this->getRouter();
        $router->get('users', fn () => [])->name('users.index');

        $report = $this->buildUseCase($router)->lint();

        // No route violations — the route is clean
        static::assertSame([], $report->errors(), 'Clean route must produce no errors.');

        // The exemption matched a live route but suppressed nothing — must appear as a stale unused entry
        $staleWaivers = $report->staleWaivers();
        static::assertNotEmpty($staleWaivers, 'A matched-but-unused config entry must appear as stale.');

        $staleString = implode("\n", $staleWaivers);
        static::assertStringContainsString('users.index', $staleString, 'Stale entry must name the exemption match key.');
        static::assertStringContainsString('suppressed nothing', $staleString, 'Stale entry must indicate nothing was suppressed.');
        static::assertStringContainsString('Pre-emptive waiver that turns out to serve no purpose.', $staleString);
    }

    /**
     * Test that the stale inline-suppression message uses "all rules" when the
     * suppression covers all rules (empty rules list), not the implode of the empty array.
     *
     * Mutant killed: #15 (Ternary flip on rules label in stale-waiver string).
     *
     * Under the mutation the ternary is flipped so an empty rules list produces
     * "rules: " (implode of []) while a non-empty list produces "rules: all rules".
     * Asserting the exact text "rules: all rules" for a suppression with an empty
     * rules list kills the mutant.
     *
     * @return void
     */
    public function testUnusedAllRulesInlineSuppressionReportsAllRulesLabel(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('users', fn () => [])
            ->name('users.index')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint([], 'Blanket suppression on a clean route.');

        $report = $this->buildUseCase($router)->lint();

        // The inline suppression fired on nothing (route is clean) → stale
        $staleWaivers = $report->staleWaivers();
        static::assertNotEmpty($staleWaivers, 'Unused all-rules suppression must be stale.');

        $staleString = implode("\n", $staleWaivers);
        // The label must say "all rules", not an empty string
        static::assertStringContainsString('rules: all rules', $staleString, 'All-rules suppression must report "rules: all rules".');
        static::assertStringContainsString('Blanket suppression on a clean route.', $staleString);
    }

    /**
     * Test that the stale inline-suppression message lists the concrete rule IDs when
     * the suppression has an explicit non-empty rules list.
     *
     * Complements testUnusedAllRulesInlineSuppressionReportsAllRulesLabel so both
     * branches of the ternary are exercised with exact assertions.
     *
     * @return void
     */
    public function testUnusedSpecificRulesInlineSuppressionReportsRuleIds(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('users', fn () => [])
            ->name('users.index')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1', 'R2'], 'Specific-rules suppression on a clean route.');

        $report = $this->buildUseCase($router)->lint();

        $staleWaivers = $report->staleWaivers();
        static::assertNotEmpty($staleWaivers, 'Unused specific-rules suppression must be stale.');

        $staleString = implode("\n", $staleWaivers);
        // The label must enumerate the actual rule IDs, not "all rules"
        static::assertStringContainsString('rules: R1, R2', $staleString, 'Specific-rules suppression must list rule IDs.');
        static::assertStringContainsString('Specific-rules suppression on a clean route.', $staleString);
    }

    /**
     * Test that a used inline suppression does NOT generate a stale entry.
     *
     * Mutant killed: #17 (TrueValue: $inlineUsed[...] = false).
     *
     * When the mutation sets the map value to false, `isset()` still returns true,
     * which means the suppression is considered "used" and no stale entry is added.
     * But wait — this mutant means a USED suppression does NOT get recorded as used,
     * so the stale-detection loop at L77 fires for it. The test verifies that a
     * suppression that actually fires on a violation produces NO stale waiver.
     *
     * @return void
     */
    public function testUsedInlineSuppressionProducesNoStaleEntry(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        // /getUsers triggers R1; inline suppression covers R1 — so suppression IS used
        $router->get('getUsers', fn () => [])
            ->name('get-users')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'Suppression that actually fires on R1.');

        $report = $this->buildUseCase($router)->lint();

        // R1 must be suppressed
        $r1Violations = array_values(array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1'));
        static::assertSame([], $r1Violations, 'R1 must be suppressed by the inline suppression.');

        // The suppression fired on a real violation — must NOT be stale
        $staleString = implode("\n", $report->staleWaivers());
        static::assertStringNotContainsString('Suppression that actually fires on R1.', $staleString, 'A used suppression must not appear as stale.');
    }

    /**
     * Test that break (not continue) stops inline-suppression matching after the
     * first suppression covering a violation fires.
     *
     * Mutant killed: #18 (Break_ → continue on the inline-suppression inner loop).
     *
     * With "continue" a second suppression on the same route would also be marked
     * as used for the same violation. The fixture has two inline suppressions:
     * the first covers R1, the second covers only R2 (which does not fire on
     * the route). Under the mutation the "continue" causes the second suppression
     * to also be iterated after the first fires, but since R1 is the violation and
     * the second suppression only covers R2, it would NOT be marked used by the
     * "continue" path either — so this specific arrangement is equivalent.
     *
     * Instead we need a case where the second suppression covers the SAME rule.
     * Two suppressions both covering R1: under correct "break" only the first is
     * marked used; under "continue" both are marked used. The second suppression
     * would then NOT be stale under the mutation, but IS stale under correct code.
     *
     * @return void
     */
    public function testFirstMatchingInlineSuppressionBreaksAndSecondIsStale(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        // /getUsers triggers R1.
        // Two suppressions both covering R1: the first fires, the second covers the
        // same rule but never gets a chance to fire (break stops iteration).
        $router->get('getUsers', fn () => [])
            ->name('get-users')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'First suppression — fires on R1.')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'Second suppression — never fires because break exits after first.');

        $report = $this->buildUseCase($router)->lint();

        // R1 must be suppressed
        $r1Violations = array_values(array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1'));
        static::assertSame([], $r1Violations, 'R1 must be suppressed.');

        // The first suppression fired → not stale; the second did not fire → stale
        $staleWaivers = $report->staleWaivers();
        $staleString  = implode("\n", $staleWaivers);
        static::assertStringContainsString(
            'Second suppression — never fires because break exits after first.',
            $staleString,
            'Second suppression must be stale because break prevents it firing.',
        );
        static::assertStringNotContainsString(
            'First suppression — fires on R1.',
            $staleString,
            'First suppression must not be stale because it fired.',
        );
    }

    /**
     * Test that all inline suppressions that fired are tracked when multiple
     * suppressions on one route each cover a different rule that fires.
     *
     * Mutant killed: #19 (ArrayOneItem: applyViolations returns only first entry of $inlineUsed).
     *
     * With the mutation, only the first entry in $inlineUsed is returned, so the
     * second suppression (which covered a different rule) is treated as unused and
     * appears as a stale waiver even though it fired. Two suppressions on the same
     * route, each covering a different rule that fires: both must be non-stale.
     *
     * @return void
     */
    public function testMultipleInlineSuppressionsOnSameRouteBothTrackedAsUsed(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        // /getUsers triggers R1 (verb in path) AND R2 (kebab-case).
        // Two suppressions: first covers R1, second covers R2. Both fire.
        $router->get('getUsers', fn () => [])
            ->name('get-users')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R1'], 'Suppresses verb-in-path violation.')
            // @phpstan-ignore method.notFound
            ->ignoreRouteLint(['R2'], 'Suppresses kebab-case violation.');

        $report = $this->buildUseCase($router)->lint();

        // R1 and R2 must both be suppressed
        $r1Violations = array_values(array_filter($report->errors(), fn ($v) => $v->ruleId === 'R1'));
        static::assertSame([], $r1Violations, 'R1 must be suppressed by the first inline suppression.');

        $r2Violations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId === 'R2',
        ));
        static::assertSame([], $r2Violations, 'R2 must be suppressed by the second inline suppression.');

        // Neither suppression should be stale — both fired
        $staleString = implode("\n", $report->staleWaivers());
        static::assertStringNotContainsString('Suppresses verb-in-path violation.', $staleString, 'First suppression must not be stale.');
        static::assertStringNotContainsString('Suppresses kebab-case violation.', $staleString, 'Second suppression must not be stale.');
    }

    /**
     * Test that two routers with the same routes registered in opposite order produce
     * byte-identical reports, confirming RouteLintReport provides a stable total order
     * independent of route registration order (NFR-01).
     *
     * @return void
     */
    public function testVerdictIsIndependentOfRouteRegistrationOrder(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        $router->get('getZzz', fn () => [])->name('zzz.index');
        $router->get('getAaa', fn () => [])->name('aaa.index');

        $report1 = $this->buildUseCase($router)->lint();

        // Register same routes in opposite registration order on a fresh router
        $router2 = $this->getRouter();
        $router2->get('getAaa', fn () => [])->name('aaa.index');
        $router2->get('getZzz', fn () => [])->name('zzz.index');

        $report2 = $this->buildUseCase($router2)->lint();

        // Both runs must produce byte-identical reports regardless of registration order (NFR-01)
        static::assertSame(
            $this->serialiseReport($report1),
            $this->serialiseReport($report2),
            'RouteLintReport must return a stable total order independent of route registration order (NFR-01).',
        );
    }

    /**
     * Test that parameter segments are split and brace-stripped so R9 inspects the
     * correct terminal literal.
     *
     * A route with two brace-wrapped parameters followed by a "create" segment is
     * linted; R9 (apiResource alignment) must fire on the literal "create" and not
     * on any parameter segment. This verifies that parameter segments are correctly
     * identified and excluded from literal-segment checks.
     *
     * @return void
     */
    public function testParametersAreExtractedAndBraceStripped(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        // Route with two parameters: {organisation} and {user}
        // The final literal segment is "create" — should trigger R9 (apiResource warning)
        // If {organisation} and {user} are not correctly identified as parameters
        // (e.g. trim not applied so they stay as "{organisation}") then the segment
        // logic in other rules would be distorted.
        $router->get('organisations/{organisation}/users/{user}/create', fn () => [])
            ->name('organisations.users.create');

        $report = $this->buildUseCase($router)->lint();

        // R9 fires on the "create" segment — confirms lastLiteralSegment correctly skips parameters
        $r9Violations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId === 'R9',
        ));
        static::assertNotEmpty($r9Violations, 'R9 must fire: "create" is an HTML-only segment.');
        static::assertSame('create', $r9Violations[0]->offendingSurface, 'Offending surface must be the literal "create" segment, not a parameter.');

        // The plural-collections rule must NOT flag {organisation} or {user} as
        // non-plural (they are parameters, not collection segments).
        // This confirms parameter segments are correctly identified and skipped.
        $r4Violations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId === 'R4' && str_contains($v->offendingSurface, '{'),
        ));
        static::assertSame([], $r4Violations, 'Parameter segments wrapped in braces must never be flagged by R4.');
    }

    /**
     * Test that a route with two distinct parameters produces violations that do
     * not reference the raw brace-wrapped parameter names, killing the trim-removal
     * mutant and the array-slice mutant together.
     *
     * Mutants killed: #35 (UnwrapTrim), #36 (ArrayOneItem on extractParameters).
     *
     * @return void
     */
    public function testMultipleParametersExtractedWithoutBraces(): void
    {
        $this->seedDefaultConfig();

        $router = $this->getRouter();
        // Two distinct parameters: {order} and {item}
        // Both must be extracted (kills #36) and without braces (kills #35).
        // An AlignmentRule check via a "create" suffix confirms parameters are seen.
        $router->get('orders/{order}/items/{item}/create', fn () => [])
            ->name('orders.items.create');

        $report = $this->buildUseCase($router)->lint();

        // R9 must fire on "create" — the rule correctly skips parameter segments
        $r9Violations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId === 'R9',
        ));
        static::assertNotEmpty($r9Violations, 'R9 must fire on the "create" segment.');

        // Surface must be exactly "create", not a brace-wrapped param
        static::assertSame('create', $r9Violations[0]->offendingSurface);

        // No R4 violation must reference a brace-wrapped parameter segment.
        // (R4 may fire on "create" as a singular segment, which is acceptable —
        // what must not happen is {order} or {item} appearing as offending surfaces.)
        $r4ParamViolations = array_values(array_filter(
            array_merge($report->errors(), $report->warnings()),
            fn ($v) => $v->ruleId === 'R4' && str_contains($v->offendingSurface, '{'),
        ));
        static::assertSame([], $r4ParamViolations, 'Parameter segments wrapped in braces must never be flagged by R4.');
    }

    /**
     * Build the LintRoutes use case with real adapters against the given router.
     *
     * Constructs the full collaborator graph directly (no container) so the test
     * is self-contained and free of binding-registrar dependencies from other tasks.
     *
     * @param  \Illuminate\Routing\Router  $router
     * @return \SineMacula\ApiToolkit\RouteLinting\LintRoutes
     */
    private function buildUseCase(Router $router): LintRoutes
    {
        $inflector = new FrameworkInflector;

        $engine = new RouteLintEngine(
            new VerbInPathRule(new SegmentNormaliser($inflector), new VerbDenylist(
                config('api-toolkit.route_linting.verb_denylist', []),
                config('api-toolkit.route_linting.remediation_hints', []),
            )),
            new KebabCaseRule,
            new LowercaseRule,
            new PluralCollectionsRule($inflector),
            new SlashSanityRule,
            new StandardMethodsRule,
            new RouteNameRule,
            new ApiResourceAlignmentRule,
            new NestingDepthRule,
        );

        return new LintRoutes(
            new RouterRouteSource($router),
            new ConfigRuleConfiguration,
            $engine,
        );
    }

    /**
     * Seed the route_linting config section used by ConfigRuleConfiguration.
     *
     * @param  array<int, array<string, string>>  $exemptions
     * @param  array<int, string>|null  $verbDenylist
     * @return void
     */
    private function seedDefaultConfig(array $exemptions = [], ?array $verbDenylist = null): void
    {
        config()->set('api-toolkit.route_linting.verb_denylist', $verbDenylist ?? self::VERB_DENYLIST);
        config()->set('api-toolkit.route_linting.remediation_hints', []);
        config()->set('api-toolkit.route_linting.exemptions', $exemptions);
        config()->set('api-toolkit.route_linting.uncountables', []);
    }

    /**
     * Get a fresh router instance bound to the booted application.
     *
     * @return \Illuminate\Routing\Router
     */
    private function getRouter(): Router
    {
        assert($this->app !== null);

        /** @var \Illuminate\Routing\Router */
        return $this->app->make('router');
    }

    /**
     * Collect the distinct route-identity strings that have at least one violation in the report.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\RouteLintReport  $report
     * @return array<int, string>
     */
    private function collectOffendingIdentities(RouteLintReport $report): array
    {
        $identities = [];

        foreach (array_merge($report->errors(), $report->warnings()) as $violation) {
            $identities[$violation->routeIdentity] = true;
        }

        return array_keys($identities);
    }

    /**
     * Determine whether any violation in the report references the given URI.
     *
     * Matches by checking whether the route identity string contains the URI
     * segment (the identity format is `METHODS uri [name]`).
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\RouteLintReport  $report
     * @param  string  $uri
     * @return bool
     */
    private function hasViolationForUri(RouteLintReport $report, string $uri): bool
    {
        foreach (array_merge($report->errors(), $report->warnings()) as $violation) {
            if (str_contains($violation->routeIdentity, ' ' . $uri . ' ') || str_ends_with($violation->routeIdentity, ' ' . $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Serialise a RouteLintReport to a stable string for byte-identical comparison.
     *
     * @param  \SineMacula\ApiToolkit\RouteLinting\RouteLintReport  $report
     * @return string
     */
    private function serialiseReport(RouteLintReport $report): string
    {
        $lines = [];

        foreach ($report->errors() as $v) {
            $lines[] = 'E|' . $v->ruleId . '|' . $v->routeIdentity . '|' . $v->offendingSurface;
        }

        foreach ($report->warnings() as $v) {
            $lines[] = 'W|' . $v->ruleId . '|' . $v->routeIdentity . '|' . $v->offendingSurface;
        }

        foreach ($report->staleWaivers() as $key) {
            $lines[] = 'S|' . $key;
        }

        return implode("\n", $lines);
    }
}
