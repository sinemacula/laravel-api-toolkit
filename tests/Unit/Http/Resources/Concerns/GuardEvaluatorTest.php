<?php

namespace Tests\Unit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;

/**
 * Tests for the GuardEvaluator stateless guard evaluation class.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(GuardEvaluator::class)]
class GuardEvaluatorTest extends TestCase
{
    /** @var \SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator */
    private GuardEvaluator $evaluator;

    /**
     * Set up the test environment.
     *
     * @return void
     */
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

        static::assertTrue($result);
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

        static::assertTrue($result);
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

        static::assertFalse($result);
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

        static::assertTrue($result);
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

        $guard = function ($res, $req) use (&$receivedArgs) {
            $receivedArgs = [$res, $req];

            return true;
        };

        $this->evaluator->passesGuards([$guard], $resource, $request);

        static::assertCount(2, $receivedArgs);
        static::assertSame($resource, $receivedArgs[0]);
        static::assertSame($request, $receivedArgs[1]);
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

        static::assertTrue($result);
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
            function () use (&$secondGuardCalled) {
                $secondGuardCalled = true;

                return true;
            },
        ];

        $this->evaluator->passesGuards($guards, new \stdClass, null);

        static::assertFalse($secondGuardCalled);
    }
}
