<?php

declare(strict_types = 1);

namespace Tests\Unit\OpenApi\Builder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\ApiToolkit\OpenApi\Builder\ScopeSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\FieldSchemaBuilder;
use SineMacula\ApiToolkit\OpenApi\Schema\RuleNormaliser;
use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;

/**
 * Tests for the named scope schema builder.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ScopeSchemaBuilder::class)]
final class ScopeSchemaBuilderTest extends TestCase
{
    /**
     * Test that each scope is keyed by its client name with its translated
     * arguments closed to others, described and marked conditional only where
     * declared so.
     *
     * @return void
     */
    public function testDescribesEachScopeByClientName(): void
    {
        $schema = new CompiledSchema([], scopes: [
            'upcoming' => new CompiledScopeDefinition('upcoming', 'upcoming'),
            'within'   => new CompiledScopeDefinition('within', 'near', ['km' => 'required|integer'], ['km'], 'Within km of the point.'),
            'mine'     => new CompiledScopeDefinition('mine', 'ownedByCaller', callerGuards: [static fn (): bool => true]),
        ]);

        self::assertSame([
            'upcoming' => ['parameters' => ['type' => 'object', 'additionalProperties' => false]],
            'within'   => [
                'description' => 'Within km of the point.',
                'parameters'  => ['type' => 'object', 'properties' => ['km' => ['type' => 'integer']], 'required' => ['km'], 'additionalProperties' => false],
            ],
            'mine' => ['parameters' => ['type' => 'object', 'additionalProperties' => false], 'conditional' => true],
        ], $this->builder()->build($schema));
    }

    /**
     * Test that a schema declaring no scope describes none.
     *
     * @return void
     */
    public function testASchemaDeclaringNoScopeDescribesNone(): void
    {
        self::assertSame([], $this->builder()->build(new CompiledSchema([])));
    }

    /**
     * Build the builder under test.
     *
     * @return \SineMacula\ApiToolkit\OpenApi\Builder\ScopeSchemaBuilder
     */
    private function builder(): ScopeSchemaBuilder
    {
        return new ScopeSchemaBuilder(new RulesToSchemaTranslator(new RuleNormaliser, new FieldSchemaBuilder));
    }
}
