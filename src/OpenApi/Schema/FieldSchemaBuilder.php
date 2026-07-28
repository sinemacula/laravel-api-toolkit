<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Builds a single field's JSON-Schema fragment from its normalised tokens.
 *
 * Given the flat token list for one field, the builder resolves the scalar
 * type, then layers on the enum, format, pattern, binary, size, and nullability
 * constraints that token list expresses. Size tokens are mapped to the keyword
 * pair matching the resolved type - lengths for strings, bounds for numbers,
 * item counts for arrays - and any token it does not recognise is skipped so
 * the field survives even when a constraint cannot be expressed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FieldSchemaBuilder
{
    /** The scalar rule tokens mapped to their JSON-Schema type */
    private const array TYPE_MAP = [
        'string'  => 'string',
        'integer' => 'integer',
        'numeric' => 'number',
        'boolean' => 'boolean',
        'array'   => 'array',
        'file'    => 'string',
        'image'   => 'string',
    ];

    /** The format rule tokens mapped to their JSON-Schema format */
    private const array FORMAT_MAP = [
        'email' => 'email',
        'uuid'  => 'uuid',
        'ulid'  => 'ulid',
        'url'   => 'uri',
        'ip'    => 'ipv4',
        'date'  => 'date',
    ];

    /**
     * Build the field schema and presence flag from the field's tokens.
     *
     * @param  list<string>  $tokens
     * @return array{schema: array<string, mixed>, required: bool}
     */
    public function build(array $tokens): array
    {
        $type   = $this->resolveType($tokens);
        $schema = $type === null ? [] : ['type' => $type];

        $this->applyEnum($schema, $tokens);
        $this->applyFormat($schema, $tokens);
        $this->applyPattern($schema, $tokens);
        $this->applyBinary($schema, $tokens);
        $this->applySize($schema, $tokens, $type);
        $this->applyNullable($schema, $tokens);

        return ['schema' => $schema, 'required' => $this->isRequired($tokens)];
    }

    /**
     * Resolve the scalar JSON-Schema type from the first matching type token,
     * or null when the field declares no scalar type.
     *
     * @param  list<string>  $tokens
     * @return string|null
     */
    private function resolveType(array $tokens): ?string
    {
        foreach (self::TYPE_MAP as $token => $type) {
            if ($this->hasToken($tokens, $token)) {
                return $type;
            }
        }

        return $this->hasFormatToken($tokens) ? 'string' : null;
    }

    /**
     * Determine whether the field carries a format token, which implies a
     * string type even when the field declares no explicit scalar type token.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    private function hasFormatToken(array $tokens): bool
    {
        if ($this->argument($tokens, 'date_format') !== null) {
            return true;
        }

        foreach (array_keys(self::FORMAT_MAP) as $token) {
            if ($this->hasToken($tokens, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply an `in:` token's comma-separated values as the field's enum.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @return void
     */
    private function applyEnum(array &$schema, array $tokens): void
    {
        $values = $this->argument($tokens, 'in');

        if ($values === null || $values === '') {
            return;
        }

        $schema['enum'] = explode(',', $values);
    }

    /**
     * Apply the field's format tokens, mapping the date_format token to a
     * date-time format.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @return void
     */
    private function applyFormat(array &$schema, array $tokens): void
    {
        foreach (self::FORMAT_MAP as $token => $format) {
            if (!$this->hasToken($tokens, $token)) {
                continue;
            }

            $schema['format'] = $format;
        }

        if ($this->argument($tokens, 'date_format') === null) {
            return;
        }

        $schema['format'] = 'date-time';
    }

    /**
     * Apply a regex token as a JSON-Schema pattern, stripping the delimiters.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @return void
     */
    private function applyPattern(array &$schema, array $tokens): void
    {
        $regex = $this->argument($tokens, 'regex');

        if ($regex === null) {
            return;
        }

        $schema['pattern'] = $this->stripDelimiters($regex);
    }

    /**
     * Mark a file or image field as a binary-format string, signalling the
     * caller to serve the body as multipart form data.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @return void
     */
    private function applyBinary(array &$schema, array $tokens): void
    {
        if (!$this->hasToken($tokens, 'file') && !$this->hasToken($tokens, 'image')) {
            return;
        }

        $schema['type']   = 'string';
        $schema['format'] = 'binary';
    }

    /**
     * Apply the size tokens as the keyword pair matching the field's type.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @param  string|null  $type
     * @return void
     */
    private function applySize(array &$schema, array $tokens, ?string $type): void
    {
        if ($type === null) {
            return;
        }

        $keys = $this->sizeKeys($type);

        if ($keys === null) {
            return;
        }

        [$min, $max] = $this->bounds($tokens);

        if ($min !== null) {
            $schema[$keys[0]] = $this->cast($min, $type);
        }

        if ($max === null) {
            return;
        }

        $schema[$keys[1]] = $this->cast($max, $type);
    }

    /**
     * Widen a nullable field so null is permitted: the resolved type becomes a
     * union with null, and null joins the enum's value set, so a nullable field
     * is never documented as rejecting the null its rule allows.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @return void
     */
    private function applyNullable(array &$schema, array $tokens): void
    {
        if (!$this->hasToken($tokens, 'nullable')) {
            return;
        }

        if (isset($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        if (!isset($schema['enum']) || !is_array($schema['enum'])) {
            return;
        }

        $schema['enum'] = [...$schema['enum'], null];
    }

    /**
     * Determine whether the field is required: a required token present and no
     * sometimes token relaxing it.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    private function isRequired(array $tokens): bool
    {
        return $this->hasToken($tokens, 'required') && !$this->hasToken($tokens, 'sometimes');
    }

    /**
     * Map a resolved type to its minimum/maximum keyword pair, or null when the
     * type carries no size vocabulary.
     *
     * @param  string  $type
     * @return array{0: string, 1: string}|null
     */
    private function sizeKeys(string $type): ?array
    {
        return match ($type) {
            'string' => ['minLength', 'maxLength'],
            'integer', 'number' => ['minimum', 'maximum'],
            'array' => ['minItems', 'maxItems'],
            default => null,
        };
    }

    /**
     * Resolve the minimum and maximum bounds from the size tokens, with size
     * pinning both and between supplying the pair.
     *
     * @param  list<string>  $tokens
     * @return array{0: string|null, 1: string|null}
     */
    private function bounds(array $tokens): array
    {
        $min = $this->argument($tokens, 'min');
        $max = $this->argument($tokens, 'max');

        if (($between = $this->argument($tokens, 'between')) !== null) {
            [$min, $max] = array_pad(explode(',', $between), 2, null);
        }

        if (($size = $this->argument($tokens, 'size')) !== null) {
            $min = $max = $size;
        }

        return [$min, $max];
    }

    /**
     * Cast a size argument to the numeric shape matching the field's type.
     *
     * @param  string  $value
     * @param  string  $type
     * @return float|int
     */
    private function cast(string $value, string $type): float|int
    {
        return $type === 'number' ? $this->numericValue($value) : (int) $value;
    }

    /**
     * Cast a numeric string to a float when fractional, otherwise an integer.
     *
     * @param  string  $value
     * @return float|int
     */
    private function numericValue(string $value): float|int
    {
        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    /**
     * Strip the surrounding delimiters and trailing modifiers from a regex.
     *
     * @param  string  $pattern
     * @return string
     */
    private function stripDelimiters(string $pattern): string
    {
        if (strlen($pattern) >= 2 && preg_match('/^(.)(.*)\1[a-zA-Z]*$/s', $pattern, $matches) === 1) {
            return $matches[2];
        }

        return $pattern;
    }

    /**
     * Determine whether a token with the given rule name is present.
     *
     * @param  list<string>  $tokens
     * @param  string  $name
     * @return bool
     */
    private function hasToken(array $tokens, string $name): bool
    {
        foreach ($tokens as $token) {
            if (explode(':', $token, 2)[0] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the argument of the named rule token, or null when the token is
     * absent or carries no argument.
     *
     * @param  list<string>  $tokens
     * @param  string  $rule
     * @return string|null
     */
    private function argument(array $tokens, string $rule): ?string
    {
        foreach ($tokens as $token) {
            $parts = explode(':', $token, 2);

            if ($parts[0] === $rule) {
                return $parts[1] ?? null;
            }
        }

        return null;
    }
}
