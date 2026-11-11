<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

use SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException;

/**
 * Validates a configured list of OpenAPI Server Objects.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ServerListValidator
{
    /** The message for a servers value that is not a list */
    private const string LIST_MESSAGE = 'Unable to export %s: they must be a list of server objects.';

    /** The message for a server entry that is not a valid Server Object */
    private const string ENTRY_MESSAGE = 'Unable to export %s: server [%d] %s.';

    /** The keys a Server Object may declare besides specification extensions */
    private const array SERVER_KEYS = ['url', 'description', 'variables'];

    /** The keys a Server Variable Object may declare besides extensions */
    private const array VARIABLE_KEYS = ['enum', 'default', 'description'];

    /**
     * Validate the configured servers, returning the list to export.
     *
     * @param  mixed  $servers
     * @param  string|null  $audience
     * @return list<array<string, mixed>>
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException
     */
    public function validate(mixed $servers, ?string $audience = null): array
    {
        if ($servers === null) {
            return [];
        }

        $scope = $audience === null ? 'the global OpenAPI servers' : sprintf('the OpenAPI servers of audience [%s]', $audience);

        if (!is_array($servers) || !array_is_list($servers)) {
            throw new ServerConfigurationException(sprintf(self::LIST_MESSAGE, $scope));
        }

        $validated = [];

        foreach ($servers as $index => $server) {

            $problem = $this->problemWith($server);

            if ($problem !== null) {
                throw new ServerConfigurationException(sprintf(self::ENTRY_MESSAGE, $scope, $index, $problem));
            }

            // @var array<string, mixed> $server
            $validated[] = $this->normalise($server);
        }

        return $validated;
    }

    /**
     * Describe the first way the entry fails to be a Server Object.
     *
     * @param  mixed  $server
     * @return string|null
     */
    private function problemWith(mixed $server): ?string
    {
        if (!is_array($server)) {
            return 'must be an array';
        }

        $url = $server['url'] ?? null;

        if (!is_string($url) || $url === '') {
            return 'must declare a non-empty string url';
        }

        return $this->optionalFieldProblem($server) ?? $this->unknownKeyProblem($server, self::SERVER_KEYS);
    }

    /**
     * Describe the first invalid optional field of a Server Object.
     *
     * @param  array<mixed, mixed>  $server
     * @return string|null
     */
    private function optionalFieldProblem(array $server): ?string
    {
        if (array_key_exists('description', $server) && !is_string($server['description'])) {
            return 'must declare its description as a string';
        }

        if (array_key_exists('variables', $server) && !$this->isVariableMap($server['variables'])) {
            return 'must declare its variables as a map of names to server variables, each with a string default, listed in its enum when it has one';
        }

        return null;
    }

    /**
     * Describe the first key outside the allowed set and not an extension.
     *
     * @param  array<mixed, mixed>  $object
     * @param  list<string>  $allowed
     * @return string|null
     */
    private function unknownKeyProblem(array $object, array $allowed): ?string
    {
        foreach (array_keys($object) as $key) {

            $key = (string) $key;

            if (!in_array($key, $allowed, true) && !str_starts_with($key, 'x-')) {
                return sprintf('declares the unsupported key [%s]', $key);
            }
        }

        return null;
    }

    /**
     * Determine whether the value maps variable names to Server Variable
     * Objects.
     *
     * @param  mixed  $variables
     * @return bool
     */
    private function isVariableMap(mixed $variables): bool
    {
        if (!is_array($variables)) {
            return false;
        }

        foreach ($variables as $name => $variable) {
            if (!is_string($name) || !$this->isVariable($variable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the value is a Server Variable Object.
     *
     * @param  mixed  $variable
     * @return bool
     */
    private function isVariable(mixed $variable): bool
    {
        if (!is_array($variable) || !is_string($variable['default'] ?? null)) {
            return false;
        }

        return $this->hasValidOptionalVariableFields($variable)
            && $this->unknownKeyProblem($variable, self::VARIABLE_KEYS) === null;
    }

    /**
     * Determine whether a Server Variable Object's enum and description are
     * valid or absent.
     *
     * @param  array<mixed, mixed>  $variable
     * @return bool
     */
    private function hasValidOptionalVariableFields(array $variable): bool
    {
        $enumValid        = !array_key_exists('enum', $variable)        || $this->isEnumOf($variable['enum'], $variable['default']);
        $descriptionValid = !array_key_exists('description', $variable) || is_string($variable['description']);

        return $enumValid && $descriptionValid;
    }

    /**
     * Determine whether the value is a non-empty list of strings that includes
     * the variable's default.
     *
     * @param  mixed  $enum
     * @param  mixed  $default
     * @return bool
     */
    private function isEnumOf(mixed $enum, mixed $default): bool
    {
        return $this->isStringList($enum) && in_array($default, $enum, true);
    }

    /**
     * Determine whether the value is a non-empty list of strings.
     *
     * @param  mixed  $values
     * @return bool
     *
     * @phpstan-assert-if-true non-empty-list<string> $values
     */
    private function isStringList(mixed $values): bool
    {
        return is_array($values)
            && $values !== []
            && array_is_list($values)
            && $values === array_filter($values, 'is_string');
    }

    /**
     * Normalise a valid Server Object, emitting empty variables as an object.
     *
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    private function normalise(array $server): array
    {
        if (($server['variables'] ?? null) === []) {
            $server['variables'] = (object) [];
        }

        return $server;
    }
}
