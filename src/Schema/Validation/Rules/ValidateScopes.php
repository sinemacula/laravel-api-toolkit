<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\CompiledScopeDefinition;
use SineMacula\ApiToolkit\Schema\Scope;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that each named scope is a local scope of the model whose parameters
 * its declared arguments bind to by name.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateScopes implements SchemaValidationRule
{
    /**
     * Validate the compiled schema for the given resource class.
     *
     * @param  string  $resourceClass
     * @param  string|null  $modelClass
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<int, \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError>
     */
    #[\Override]
    public function validate(string $resourceClass, ?string $modelClass, CompiledSchema $schema): array
    {
        if ($modelClass === null || !is_a($modelClass, Model::class, true)) {
            return [];
        }

        $errors = [];

        foreach ($schema->getScopes() as $scope) {
            foreach ($this->collectDefects($scope, $modelClass) as $defect) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: Scope::KEY_PREFIX . $scope->name,
                    defect: $defect,
                );
            }
        }

        return $errors;
    }

    /**
     * Collect the defects in one scope's binding to its model method.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $scope
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return list<string>
     */
    private function collectDefects(CompiledScopeDefinition $scope, string $modelClass): array
    {
        $method = $scope->method($modelClass);

        if ($method === null) {
            return [sprintf('Scope "%s" is not a local scope of model "%s"', $scope->scope, $modelClass)];
        }

        return [...$this->collectArgumentDefects($scope, $method), ...$this->collectParameterDefects($scope, $method)];
    }

    /**
     * Collect the declared arguments that bind to no parameter of the method.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $scope
     * @param  \ReflectionMethod  $method
     * @return list<string>
     */
    private function collectArgumentDefects(CompiledScopeDefinition $scope, \ReflectionMethod $method): array
    {
        $parameters = $method->getParameters();
        $builder    = array_shift($parameters)?->getName();
        $variadic   = array_filter($parameters, static fn (\ReflectionParameter $parameter): bool => $parameter->isVariadic()) !== [];
        $named      = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters);
        $defects    = [];

        foreach ($scope->arguments as $argument) {

            if ($argument === $builder) {
                $defects[] = sprintf('Argument "%s" names the builder parameter of method "%s"', $argument, $method->getName());
                continue;
            }

            if ($variadic || in_array($argument, $named, true)) {
                continue;
            }

            $defects[] = sprintf('Argument "%s" names no parameter of method "%s"', $argument, $method->getName());
        }

        return $defects;
    }

    /**
     * Collect the parameters after the builder that no declared argument or
     * default fills.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledScopeDefinition  $scope
     * @param  \ReflectionMethod  $method
     * @return list<string>
     */
    private function collectParameterDefects(CompiledScopeDefinition $scope, \ReflectionMethod $method): array
    {
        $defects = [];

        foreach (array_slice($method->getParameters(), 1) as $parameter) {

            if ($parameter->isVariadic() || $parameter->isDefaultValueAvailable() || in_array($parameter->getName(), $scope->arguments, true)) {
                continue;
            }

            $defects[] = sprintf('Parameter "%s" of method "%s" has no default and is not a declared argument', $parameter->getName(), $method->getName());
        }

        return $defects;
    }
}
