<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

use SineMacula\ApiToolkit\OpenApi\Naming\SchemaComponentName;

/**
 * Builds a single field's JSON-Schema fragment from its normalised tokens.
 *
 * Unrecognised tokens are skipped, so the field survives a constraint it cannot
 * express.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FieldSchemaBuilder
{
    /** The path prefix under which component schemas are referenced */
    private const string SCHEMA_REF_PREFIX = '#/components/schemas/';

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
        'ipv4'  => 'ipv4',
        'ipv6'  => 'ipv6',
    ];

    /** The rule tokens accepting only a string, beyond the format tokens */
    private const array STRING_RULES = ['date', 'ip'];

    /** The values the accepted and declined rules admit */
    private const array ACCEPTANCE_VALUES = [
        'accepted' => ['yes', 'on', '1', 1, true, 'true'],
        'declined' => ['no', 'off', '0', 0, false, 'false'],
    ];

    /**
     * Constructor.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $enums
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\InRuleValues  $inValues
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumValuesToken  $exactValues
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\FieldPresence  $presence
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\PatternConstraintBuilder  $patterns
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\RangeConstraintBuilder  $ranges
     */
    public function __construct(

        /** The registry collecting enum classes referenced by a $ref. */
        private EnumSchemaRegistry $enums = new EnumSchemaRegistry,

        /** The reader resolving an `in:` argument into enum values. */
        private InRuleValues $inValues = new InRuleValues,

        /** The reader resolving an `enum-values:` argument into enum values. */
        private EnumValuesToken $exactValues = new EnumValuesToken,

        /** The reader of how the field's rules govern its presence. */
        private FieldPresence $presence = new FieldPresence,

        /** The builder of the field's pattern keywords. */
        private PatternConstraintBuilder $patterns = new PatternConstraintBuilder,

        /** The builder of the field's size, bound and divisor keywords. */
        private RangeConstraintBuilder $ranges = new RangeConstraintBuilder,
    ) {}

    /**
     * Build the field schema and presence flag from the field's tokens.
     *
     * A prohibited or missing field is the false schema and never required.
     *
     * @param  list<string>  $tokens
     * @return array{schema: array<string, mixed>|false, required: bool}
     */
    public function build(array $tokens): array
    {
        if ($this->hasToken($tokens, 'prohibited') || $this->hasToken($tokens, 'missing')) {
            return ['schema' => false, 'required' => false];
        }

        $enumClass = $this->argument($tokens, 'enum-class');

        if ($enumClass !== null && $enumClass !== '') {
            return ['schema' => $this->enumReferenceSchema($enumClass, $tokens), 'required' => $this->presence->isRequired($tokens)];
        }

        $type   = $this->resolveType($tokens);
        $schema = $type === null ? [] : ['type' => $type];

        $this->applyEnum($schema, $tokens, $type);
        $this->applyAcceptance($schema, $tokens, $type);
        $this->applyExclusion($schema, $tokens, $type);
        $this->applyFormat($schema, $tokens);

        $schema = [...$schema, ...$this->patterns->build($tokens, $type)];

        $this->applyBinary($schema, $tokens);

        // A file's size is its kilobytes, which no keyword expresses
        if ($type !== null && !$this->isFile($tokens)) {
            $schema = [...$schema, ...$this->ranges->build($tokens, $type)];
        }

        $this->applyNullable($schema, $tokens);

        return ['schema' => $schema, 'required' => $this->presence->isRequired($tokens)];
    }

    /**
     * Build a reference to the enum's named component, registering the enum.
     *
     * A nullable field widens to anyOf, since a bare $ref cannot carry null.
     *
     * @param  string  $enumClass
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    private function enumReferenceSchema(string $enumClass, array $tokens): array
    {
        if (!enum_exists($enumClass)) {
            return [];
        }

        $this->enums->register($enumClass);

        $reference = ['$ref' => self::SCHEMA_REF_PREFIX . SchemaComponentName::fromClass($enumClass)];

        return $this->presence->acceptsNull($tokens)
            ? ['anyOf' => [$reference, ['type' => 'null']]]
            : $reference;
    }

    /**
     * Resolve the scalar JSON-Schema type from the first matching type token.
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

        return $this->impliesString($tokens) ? 'string' : null;
    }

    /**
     * Determine whether the field carries a token accepting only a string.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    private function impliesString(array $tokens): bool
    {
        if ($this->argument($tokens, 'date_format') !== null) {
            return true;
        }

        foreach ([...array_keys(self::FORMAT_MAP), ...self::STRING_RULES] as $token) {
            if ($this->hasToken($tokens, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply an `enum-values:` or `in:` token's values as the field's enum.
     *
     * On an array field `in:` constrains the items, since each one is checked.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @param  string|null  $type
     * @return void
     */
    private function applyEnum(array &$schema, array $tokens, ?string $type): void
    {
        $exact = $this->exactValues->values($this->argument($tokens, EnumValuesToken::NAME) ?? '');

        if ($exact !== null) {
            $schema['enum'] = $type === 'string' ? array_map(strval(...), $exact) : $exact;

            return;
        }

        $values = $this->argument($tokens, 'in');

        if ($values === null || $values === '') {
            return;
        }

        if ($type === 'array') {
            $schema['items'] = ['enum' => $this->inValues->forItems($values)];

            return;
        }

        $schema['enum'] = $this->inValues->forField($values, $type);
    }

    /**
     * Narrow the field's enum to the values an accepted or declined rule
     * admits.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @param  string|null  $type
     * @return void
     */
    private function applyAcceptance(array &$schema, array $tokens, ?string $type): void
    {
        foreach (self::ACCEPTANCE_VALUES as $rule => $values) {
            if (!$this->hasToken($tokens, $rule)) {
                continue;
            }

            $admitted = $type === null ? $values : array_values(array_filter($values, fn (bool|int|string $value): bool => $this->isOfType($value, $type)));

            $schema['enum'] = is_array($schema['enum'] ?? null)
                ? array_values(array_filter($schema['enum'], static fn (mixed $value): bool => in_array($value, $admitted, true)))
                : $admitted;
        }
    }

    /**
     * Determine whether an acceptance value is of the field's JSON type.
     *
     * @param  bool|int|string  $value
     * @param  string  $type
     * @return bool
     */
    private function isOfType(bool|int|string $value, string $type): bool
    {
        return match ($type) {
            'string'            => is_string($value),
            'integer', 'number' => is_int($value),
            'boolean'           => is_bool($value),
            default             => false,
        };
    }

    /**
     * Apply a `not_in:` token's values as the enum the field must not match.
     *
     * An array is refused only when wholly inside the list, so it is skipped.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @param  string|null  $type
     * @return void
     */
    private function applyExclusion(array &$schema, array $tokens, ?string $type): void
    {
        $values = $this->argument($tokens, 'not_in');

        if ($values === null || $type === 'array') {
            return;
        }

        $excluded = $this->inValues->forExclusion($values, $type);

        if ($excluded === []) {
            return;
        }

        $schema['not'] = ['enum' => $excluded];
    }

    /**
     * Apply the field's format tokens, mapping the date_format token to a
     * date-time format.
     *
     * The ip rule admits either address family, and json any encoded value.
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

        if ($this->hasToken($tokens, 'ip')) {
            $schema['anyOf'] = [['format' => 'ipv4'], ['format' => 'ipv6']];
        }

        if ($this->argument($tokens, 'date_format') !== null) {
            $schema['format'] = 'date-time';
        }

        if (!$this->hasToken($tokens, 'json')) {
            return;
        }

        $schema['contentMediaType'] = 'application/json';
    }

    /**
     * Mark a file or image field as a binary-format string.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @return void
     */
    private function applyBinary(array &$schema, array $tokens): void
    {
        if (!$this->isFile($tokens)) {
            return;
        }

        $schema['type']   = 'string';
        $schema['format'] = 'binary';
    }

    /**
     * Determine whether the field is an uploaded file.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    private function isFile(array $tokens): bool
    {
        return $this->hasToken($tokens, 'file') || $this->hasToken($tokens, 'image');
    }

    /**
     * Widen a nullable field's type and enum to admit null.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $tokens
     * @return void
     */
    private function applyNullable(array &$schema, array $tokens): void
    {
        if (!$this->presence->acceptsNull($tokens)) {
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
     * Read the argument of the named rule token.
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
