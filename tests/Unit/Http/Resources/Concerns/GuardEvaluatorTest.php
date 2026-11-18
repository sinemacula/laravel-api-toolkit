<?php

declare(strict_types = 1);

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;

/**
 * Tests for the GuardEvaluator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(GuardEvaluator::class)]
final class GuardEvaluatorTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator */
    private GuardEvaluator $evaluator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new GuardEvaluator;
    }

    /**
     * Test that an empty guards array returns true.
     *
     * @return void
     */
    public function testPassesGuardsReturnsTrueForEmptyGuardsArray(): void
    {
        $result = $this->evaluator->passesGuards([], new \stdClass, null);

        self::assertTrue($result);
    }

    /**
     * Test that all guards returning true results in true.
     *
     * @return void
     */
    public function testPassesGuardsReturnsTrueWhenAllGuardsPass(): void
    {
        $guards = [
            fn ($resource, $request) => true,
            fn ($resource, $request) => true,
        ];

        $result = $this->evaluator->passesGuards($guards, new \stdClass, null);

        self::assertTrue($result);
    }

    /**
     * Test that a guard returning false causes the method to return false.
     *
     * @return void
     */
    public function testPassesGuardsReturnsFalseWhenAnyGuardReturnsFalse(): void
    {
        $guards = [
            fn ($resource, $request) => true,
            fn ($resource, $request) => false,
        ];

        $result = $this->evaluator->passesGuards($guards, new \stdClass, null);

        self::assertFalse($result);
    }

    /**
     * Test that non-callable guard entries are skipped without error.
     *
     * @return void
     */
    public function testPassesGuardsSkipsNonCallableGuards(): void
    {
        $guards = [
            'not-a-callable',
            42,
            fn ($resource, $request) => true,
        ];

        $result = $this->evaluator->passesGuards($guards, new \stdClass, null);

        self::assertTrue($result);
    }

    /**
     * Test that each guard receives the resource and request as arguments.
     *
     * @return void
     */
    public function testPassesGuardsPassesResourceAndRequestToGuard(): void
    {

        /** @var array<int, mixed> $receivedArgs */
        $receivedArgs = [];

        $resource = new \stdClass;
        $request  = new Request;

        $guard = function ($res, $req) use (&$receivedArgs): bool {
            $receivedArgs = [$res, $req];

            return true;
        };

        $this->evaluator->passesGuards([$guard], $resource, $request);

        self::assertCount(2, $receivedArgs);
        self::assertSame($resource, $receivedArgs[0]);
        self::assertSame($request, $receivedArgs[1]);
    }

    /**
     * Test that a guard returning null is treated as passing.
     *
     * @return void
     */
    public function testPassesGuardsReturnsTrueWhenGuardReturnsNull(): void
    {
        $guards = [
            fn ($resource, $request) => null,
        ];

        $result = $this->evaluator->passesGuards($guards, new \stdClass, null);

        self::assertTrue($result);
    }

    /**
     * Test that evaluation short-circuits on the first failing guard.
     *
     * @return void
     */
    public function testPassesGuardsShortCircuitsOnFirstFailure(): void
    {
        $secondGuardCalled = false;

        $guards = [
            fn ($resource, $request) => false,
            function () use (&$secondGuardCalled): bool {
                $secondGuardCalled = true;

                return true;
            },
        ];

        $this->evaluator->passesGuards($guards, new \stdClass, null);

        self::assertFalse($secondGuardCalled);
    }

    /**
     * Provide caller guard results and whether each passes.
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function callerGuardResults(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'null' => [null, true];
        yield 'zero' => [0, true];
    }

    /**
     * Test that a caller guard hides only on a result of exactly false.
     *
     * @param  mixed  $result
     * @param  bool  $passes
     * @return void
     */
    #[DataProvider('callerGuardResults')]
    public function testPassesCallerGuardsHidesOnlyOnExactlyFalse(mixed $result, bool $passes): void
    {
        self::assertSame($passes, $this->evaluator->passesCallerGuards([static fn (): mixed => $result], null));
    }

    /**
     * Test that every caller guard is handed the request alone, a non-callable
     * entry is skipped, and the first refusal stops the rest.
     *
     * @return void
     */
    public function testPassesCallerGuardsHandsEachGuardTheRequestAloneUntilOneRefuses(): void
    {
        $request  = Request::create('/');
        $received = [];

        $record = static function (bool $result) use (&$received): \Closure {
            return static function (mixed ...$arguments) use (&$received, $result): bool {

                $received[] = $arguments;

                return $result;
            };
        };

        self::assertTrue($this->evaluator->passesCallerGuards(['not callable', $record(true)], $request));
        self::assertFalse($this->evaluator->passesCallerGuards([$record(false), $record(true)], $request));
        self::assertSame([[$request], [$request]], $received);
    }
}
