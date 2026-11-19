<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Builder;

use SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;

/**
 * Describes the named scopes a resource declares, each with the JSON Schema of
 * the arguments it takes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ScopeSchemaBuilder
{
    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RulesToSchemaTranslator  $translator
     */
    public function __construct(

        /** The translator of each scope's argument rules. */
        private RulesToSchemaTranslator $translator,
    ) {}

    /**
     * Describe each named scope of the schema, keyed by its client name.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $compiled
     * @return array<string, array<string, mixed>>
     */
    public function build(CompiledSchema $compiled): array
    {
        return array_map($this->describe(...), $compiled->getScopes());
    }

    /**
     * Describe one named scope, closing its arguments to undeclared ones.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $scope
     * @return array<string, mixed>
     */
    private function describe(CompiledScopeDefinition $scope): array
    {
        $described = $scope->description === null ? [] : ['description' => $scope->description];

        $described['parameters'] = [
            'type' => 'object',
            ...$this->translator->translate($scope->parameters),
            'additionalProperties' => false,
        ];

        if ($scope->callerGuards !== []) {
            $described['conditional'] = true;
        }

        return $described;
    }
}
