<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Repositories\Criteria;

use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator;
use SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;

/**
 * Admits a request to the query keys only caller-guarded fields declare, and to
 * caller-guarded scopes.
 *
 * Each field's or scope's caller guards run at most once per request, and only
 * for a key or scope the request uses.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CallerAdmission
{
    /** @var \WeakMap<\SineMacula\ApiToolkit\Schema\CompiledSchema, array<string, bool>> Whether each field passed, keyed by its resource's schema */
    private \WeakMap $passed;

    /** @var \WeakMap<\SineMacula\ApiToolkit\Schema\CompiledScopeDefinition, bool> Whether each scope passed */
    private \WeakMap $scopes;

    /**
     * Create a new caller admission for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \SineMacula\ApiToolkit\Http\Resources\Concerns\GuardEvaluator  $guards
     */
    public function __construct(

        /** The request the caller guards are asked about */
        private readonly Request $request,

        /** Evaluates each field's caller guards */
        private readonly GuardEvaluator $guards,
    ) {
        $this->passed = new \WeakMap;
        $this->scopes = new \WeakMap;
    }

    /**
     * Determine whether any of the declaring fields passes its caller guards.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @param  list<string>  $declarers
     * @return bool
     */
    public function admits(CompiledSchema $schema, array $declarers): bool
    {
        foreach ($declarers as $declarer) {
            if ($this->passes($schema, $declarer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the scope passes its caller guards, once per request.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $scope
     * @return bool
     */
    public function admitsScope(CompiledScopeDefinition $scope): bool
    {
        return $this->scopes[$scope] ??= $this->guards->passesCallerGuards($scope->callerGuards, $this->request);
    }

    /**
     * Determine whether an aggregate passes its caller guards.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledAggregateDefinition  $aggregate
     * @return bool
     */
    public function admitsAggregate(CompiledAggregateDefinition $aggregate): bool
    {
        return $this->guards->passesCallerGuards($aggregate->callerGuards, $this->request);
    }

    /**
     * Determine whether one field passes its caller guards, once per request.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @param  string  $field
     * @return bool
     */
    private function passes(CompiledSchema $schema, string $field): bool
    {
        $passed = $this->passed[$schema] ?? [];

        if (!array_key_exists($field, $passed)) {
            $passed[$field]        = $this->guards->passesCallerGuards($schema->getField($field)->callerGuards ?? [], $this->request);
            $this->passed[$schema] = $passed;
        }

        return $passed[$field];
    }
}
