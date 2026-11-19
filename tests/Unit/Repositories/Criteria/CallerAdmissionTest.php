<?php

declare(strict_types = 1);

namespace Tests\Unit\Repositories\Criteria;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\Enums\AggregateFunction;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Repositories\Criteria\CallerAdmission;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledFieldDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;

/**
 * Tests for the per-request admission to caller-guarded query keys.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CallerAdmission::class)]
final class CallerAdmissionTest extends TestCase
{
    /** @var array<string, int> How often each field's caller guard ran */
    private array $runs = [];

    /**
     * Test that a key is admitted when any of its declaring fields passes, and
     * refused when none does.
     *
     * @return void
     */
    public function testAdmitsWhenAnyDeclaringFieldPasses(): void
    {
        $schema    = $this->schema(['hidden' => false, 'shown' => true, 'also_hidden' => false]);
        $admission = new CallerAdmission(Request::create('/'), new GuardEvaluator);

        self::assertTrue($admission->admits($schema, ['hidden', 'shown']));
        self::assertFalse($admission->admits($schema, ['hidden', 'also_hidden']));
        self::assertFalse($admission->admits($schema, []));
    }

    /**
     * Test that each field's caller guards run once per request, however often
     * a key it declares is asked about.
     *
     * @return void
     */
    public function testRunsEachFieldsCallerGuardsOncePerRequest(): void
    {
        $schema    = $this->schema(['hidden' => false, 'shown' => true]);
        $admission = new CallerAdmission(Request::create('/'), new GuardEvaluator);

        $admission->admits($schema, ['hidden', 'shown']);
        $admission->admits($schema, ['shown', 'hidden']);
        $admission->admits($schema, ['hidden']);

        self::assertSame(['hidden' => 1, 'shown' => 1], $this->runs);

        (new CallerAdmission(Request::create('/'), new GuardEvaluator))->admits($schema, ['shown']);

        self::assertSame(['hidden' => 1, 'shown' => 2], $this->runs);
    }

    /**
     * Test that a field key is remembered per resource schema, so another
     * resource's field of the same key runs its own guards.
     *
     * @return void
     */
    public function testRemembersEachFieldPerResourceSchema(): void
    {
        $admission = new CallerAdmission(Request::create('/'), new GuardEvaluator);

        self::assertTrue($admission->admits($this->schema(['email' => true]), ['email']));
        self::assertFalse($admission->admits($this->schema(['email' => false]), ['email']));
    }

    /**
     * Test that each caller guard is asked about the admitted request.
     *
     * @return void
     */
    public function testAsksEachCallerGuardAboutTheRequest(): void
    {
        $request  = Request::create('/');
        $received = [];

        $schema = new CompiledSchema(['email' => $this->field([static function (mixed ...$arguments) use (&$received): bool {

            $received[] = $arguments;

            return true;
        }])]);

        (new CallerAdmission($request, new GuardEvaluator))->admits($schema, ['email']);

        self::assertSame([[$request]], $received);
    }

    /**
     * Test that a scope is admitted only when every caller guard it carries
     * passes, each asked once per request about the request.
     *
     * @return void
     */
    public function testAdmitsAScopeWhoseCallerGuardsAllPassOncePerRequest(): void
    {
        $request  = Request::create('/');
        $received = [];
        $guard    = static function (mixed ...$arguments) use (&$received): bool {

            $received[] = $arguments;

            return true;
        };

        $admitted  = new CompiledScopeDefinition('mine', 'ownedByCaller', callerGuards: [$guard]);
        $refused   = new CompiledScopeDefinition('staff', 'staff', callerGuards: [$guard, static fn (): bool => false]);
        $admission = new CallerAdmission($request, new GuardEvaluator);

        self::assertTrue($admission->admitsScope($admitted));
        self::assertTrue($admission->admitsScope($admitted));
        self::assertFalse($admission->admitsScope($refused));
        self::assertTrue($admission->admitsScope(new CompiledScopeDefinition('upcoming', 'upcoming')));
        self::assertSame([[$request], [$request]], $received);
    }

    /**
     * Test that an aggregate is admitted only when every caller guard it
     * carries passes.
     *
     * @return void
     */
    public function testAdmitsAnAggregateOnlyWhenItsCallerGuardsPass(): void
    {
        $admission = new CallerAdmission(Request::create('/'), new GuardEvaluator);
        $aggregate = static fn (array $guards): CompiledAggregateDefinition => new CompiledAggregateDefinition(
            presentKey  : 'posts_count',
            relation    : 'posts',
            function    : AggregateFunction::COUNT,
            column      : null,
            constraint  : null,
            isDefault   : false,
            guards      : [],
            callerGuards: $guards,
        );

        self::assertTrue($admission->admitsAggregate($aggregate([])));
        self::assertTrue($admission->admitsAggregate($aggregate([static fn (): bool => true])));
        self::assertFalse($admission->admitsAggregate($aggregate([static fn (): bool => true, static fn (): bool => false])));
    }

    /**
     * Build a schema whose fields carry a counted caller guard returning the
     * given result.
     *
     * @param  array<string, bool>  $results
     * @return \SineMacula\ApiToolkit\Schema\CompiledSchema
     */
    private function schema(array $results): CompiledSchema
    {
        $fields = [];

        foreach ($results as $key => $result) {
            $fields[$key] = $this->field([function () use ($key, $result): bool {

                $this->runs[$key] = ($this->runs[$key] ?? 0) + 1;

                return $result;
            }]);
        }

        return new CompiledSchema($fields);
    }

    /**
     * Build a plain field definition carrying the given caller guards.
     *
     * @param  array<int, callable(mixed): bool>  $callerGuards
     * @return \SineMacula\ApiToolkit\Schema\CompiledFieldDefinition
     */
    private function field(array $callerGuards): CompiledFieldDefinition
    {
        return new CompiledFieldDefinition(
            accessor    : null,
            compute     : null,
            relation    : null,
            resource    : null,
            fields      : null,
            constraint  : null,
            extras      : [],
            needs       : [],
            guards      : [],
            transformers: [],
            callerGuards: $callerGuards,
        );
    }
}
