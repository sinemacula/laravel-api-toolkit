<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

use Illuminate\Validation\ValidationException;
use SineMacula\ApiToolkit\Http\Resources\ResourceTypeIndex;
use SineMacula\ApiToolkit\Schema\SchemaCompiler;
use SineMacula\ApiToolkit\Validation\Rejection;

/**
 * Rejects selections naming a resource type or a name the server does not
 * declare.
 *
 * A name is checked against every catalogued resource of the type it is keyed
 * by, whatever the route, so one selection is valid on every endpoint.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class QuerySelectionValidator
{
    /**
     * Validate the extracted selection parameters, reporting every unknown type
     * and name at once.
     *
     * @param  array<string, mixed>  $parameters
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(array $parameters): void
    {
        $messages = [];

        foreach (QueryParameterValidator::SELECTIONS as $parameter) {

            /** @var array<array-key, array<int, string>> $selections */
            $selections = $parameters[$parameter] ?? [];

            foreach ($selections as $type => $names) {

                $unknown = $this->unknown($parameter, (string) $type, $names);

                if ($unknown === []) {
                    continue;
                }

                $messages[$parameter . '.' . $type] = $unknown;
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Describe each unknown in one selection, or the type itself when no
     * catalogued resource carries it.
     *
     * @param  string  $parameter
     * @param  string  $type
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function unknown(string $parameter, string $type, array $names): array
    {
        $classes = ResourceTypeIndex::classesFor($type);

        if ($classes === []) {
            return [Rejection::message($parameter . '.unknown_type', ['type' => $type])];
        }

        $accepted = $this->accepted($parameter, $classes);
        $messages = [];

        foreach ($names as $name) {

            if ($name === '' || in_array($name, $accepted, true)) {
                continue;
            }

            $messages[] = Rejection::message($parameter . '.undeclared', ['name' => $name, 'type' => $type]);
        }

        return $messages;
    }

    /**
     * Return the names the given resources accept under the parameter.
     *
     * @param  string  $parameter
     * @param  array<int, class-string<\SineMacula\ApiToolkit\Contracts\ApiResourceInterface>>  $classes
     * @return array<int, string>
     */
    private function accepted(string $parameter, array $classes): array
    {
        $accepted = $parameter === 'fields' ? [':all'] : [];

        foreach ($classes as $class) {

            $schema = SchemaCompiler::compile($class);

            array_push($accepted, ...($parameter === 'fields'
                ? $schema->getSelectableFields()
                : array_keys($schema->getAggregateDefinitions())));
        }

        return $accepted;
    }
}
