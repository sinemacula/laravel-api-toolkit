<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Validation\Rules;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider;
use SineMacula\ApiToolkit\Contracts\SchemaValidationRule;
use SineMacula\ApiToolkit\Schema\CompiledSchema;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationError;

/**
 * Validate that every needs() declaration names a column the table carries.
 *
 * Column matching is exact, since quoted identifiers may be case-sensitive.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ValidateNeedsColumns implements SchemaValidationRule
{
    /**
     * Create a new needs column validation rule.
     *
     * @param  \SineMacula\ApiToolkit\Contracts\SchemaIntrospectionProvider  $introspector
     * @return void
     */
    public function __construct(

        /** Reads the column listing behind the model's table */
        private SchemaIntrospectionProvider $introspector,
    ) {}

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
        $declared = $this->declaredNeeds($schema);
        $model    = $declared === [] ? null : $this->resolveModel($modelClass);

        if ($model === null) {
            return [];
        }

        $columns = $this->introspector->getColumns($model);

        // An empty listing means the columns are unknown
        if ($columns === []) {
            return [];
        }

        $errors = [];

        foreach ($declared as $key => $needs) {

            foreach (array_diff($needs, $columns) as $column) {
                $errors[] = new SchemaValidationError(
                    resourceClass: $resourceClass,
                    fieldKey: $key,
                    defect: sprintf('Field declares needs() on "%s", and table "%s" carries no such column', $column, $model->getTable()),
                );
            }
        }

        return $errors;
    }

    /**
     * Return the columns each field declares through needs(), by key.
     *
     * @param  \SineMacula\ApiToolkit\Schema\CompiledSchema  $schema
     * @return array<string, array<int, string>>
     */
    private function declaredNeeds(CompiledSchema $schema): array
    {
        $declared = [];

        foreach ($schema->getFieldKeys() as $key) {

            $needs = $schema->getField($key)->needs ?? [];

            if ($needs === []) {
                continue;
            }

            $declared[$key] = $needs;
        }

        return $declared;
    }

    /**
     * Resolve the model behind the resource, or null when it is not a model.
     *
     * @param  string|null  $modelClass
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    private function resolveModel(?string $modelClass): ?Model
    {
        if ($modelClass === null || !is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var \Illuminate\Database\Eloquent\Model */
        return new $modelClass;
    }
}
