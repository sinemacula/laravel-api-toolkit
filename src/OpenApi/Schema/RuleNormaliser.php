<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

use Illuminate\Contracts\Validation\ParameterizedRule;
use Illuminate\Validation\Rules\ArrayRule;
use Illuminate\Validation\Rules\Confirmed;
use Illuminate\Validation\Rules\Date;
use Illuminate\Validation\Rules\Distinct;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\ExcludeIf;
use Illuminate\Validation\Rules\ExcludeUnless;
use Illuminate\Validation\Rules\ExcludeWith;
use Illuminate\Validation\Rules\ExcludeWithout;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\ImageFile;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\IpAddress;
use Illuminate\Validation\Rules\NotIn;
use Illuminate\Validation\Rules\Numeric;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rules\Regex;
use Illuminate\Validation\Rules\RequiredWith;
use Illuminate\Validation\Rules\RequiredWithAll;
use Illuminate\Validation\Rules\RequiredWithout;
use Illuminate\Validation\Rules\RequiredWithoutAll;
use Illuminate\Validation\Rules\StringRule;
use Illuminate\Validation\Rules\Url;
use Illuminate\Validation\Rules\Uuid;
use SineMacula\ApiToolkit\Validation\Rules\PureEnum;

/**
 * Flattens a Laravel rule definition into a list of string tokens.
 *
 * A framework rule object is expanded as the validator reads it, matched on its
 * exact class so no other rule's code runs; any other object is dropped.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class RuleNormaliser
{
    /** The fluent rule objects the validator splits on pipes */
    private const array PIPED_RULES = [ArrayRule::class, Date::class, Numeric::class, StringRule::class];

    /** The rule objects the validator reads as a single rule string */
    private const array STRING_RULES = [In::class, NotIn::class, Regex::class, IpAddress::class];

    /** The rule objects the validator reads through their parameters */
    private const array PARAMETERISED_RULES = [
        Url::class, Uuid::class, Distinct::class, Confirmed::class,
        RequiredWith::class, RequiredWithAll::class, RequiredWithout::class, RequiredWithoutAll::class,
        ExcludeWith::class, ExcludeWithout::class,
    ];

    /** The rule objects mapped to the rules they imply */
    private const array IMPLIED_RULES = [
        File::class      => ['file'],
        ImageFile::class => ['file', 'image'],
        Email::class     => ['email'],
    ];

    /** The exclude rule objects mapped to the rule each expresses */
    private const array EXCLUDE_RULES = [
        ExcludeIf::class      => 'exclude_if',
        ExcludeUnless::class  => 'exclude_unless',
        ExcludeWith::class    => 'exclude_with',
        ExcludeWithout::class => 'exclude_without',
    ];

    /**
     * Normalise a rule definition into a flat list of string tokens.
     *
     * @param  mixed  $definition
     * @return list<string>
     */
    public function normalise(mixed $definition): array
    {
        return match (true) {
            is_string($definition) => $this->splitPipes($definition),
            is_array($definition)  => $this->normaliseArray($definition),
            is_object($definition) => $this->expandObject($definition),
            default                => [],
        };
    }

    /**
     * Normalise an array rule definition, expanding each rule object.
     *
     * String elements are kept whole, as the validator reads them, so a pipe in
     * a pattern survives.
     *
     * @param  array<array-key, mixed>  $definition
     * @return list<string>
     */
    private function normaliseArray(array $definition): array
    {
        $tokens = [];

        foreach ($definition as $element) {
            if (is_string($element) && $element !== '') {
                $tokens[] = $element;
            } elseif (is_object($element)) {
                $tokens = array_merge($tokens, $this->expandObject($element));
            }
        }

        return $tokens;
    }

    /**
     * Split a pipe-delimited rule string into its non-empty tokens.
     *
     * @param  string  $rules
     * @return list<string>
     */
    private function splitPipes(string $rules): array
    {
        return array_values(array_filter(explode('|', $rules), static fn (string $token): bool => $token !== ''));
    }

    /**
     * Expand a rule object into the tokens it contributes.
     *
     * @param  object  $rule
     * @return list<string>
     */
    private function expandObject(object $rule): array
    {
        $class = $rule::class;

        return match (true) {
            $rule instanceof Enum                                                                   => $this->enumTokens($rule),
            $rule instanceof PureEnum                                                               => $this->pureEnumTokens($rule),
            $rule instanceof Password                                                               => $this->passwordTokens($rule),
            $rule instanceof \Stringable       && in_array($class, self::PIPED_RULES, true)         => $this->splitPipes((string) $rule),
            $rule instanceof \Stringable       && in_array($class, self::STRING_RULES, true)        => [(string) $rule],
            $rule instanceof ParameterizedRule && in_array($class, self::PARAMETERISED_RULES, true) => [$this->parameterisedToken($rule)],
            isset(self::IMPLIED_RULES[$class])                                                      => self::IMPLIED_RULES[$class],
            default                                                                                 => $this->excludeTokens($rule),
        };
    }

    /**
     * Format a parameterised rule as the rule string the validator parses.
     *
     * @param  \Illuminate\Contracts\Validation\ParameterizedRule  $rule
     * @return string
     */
    private function parameterisedToken(ParameterizedRule $rule): string
    {
        $parameters = $rule->toArray();
        $name       = $this->stringValue(array_shift($parameters));

        return $parameters === [] ? $name : $name . ':' . implode(',', array_map($this->formatParameter(...), $parameters));
    }

    /**
     * Quote a rule parameter holding a comma or quote, as the framework does.
     *
     * @param  mixed  $parameter
     * @return string
     */
    private function formatParameter(mixed $parameter): string
    {
        $value = $this->stringValue($parameter);

        return str_contains($value, ',') || str_contains($value, '"')
            ? '"' . str_replace('"', '""', $value) . '"'
            : $value;
    }

    /**
     * Cast a rule parameter to its string form.
     *
     * @param  mixed  $value
     * @return string
     */
    private function stringValue(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /**
     * Expand any other exclude rule object into the bare name of its rule.
     *
     * @param  object  $rule
     * @return list<string>
     */
    private function excludeTokens(object $rule): array
    {
        return array_values(array_filter(self::EXCLUDE_RULES, static fn (string $class): bool => $rule instanceof $class, ARRAY_FILTER_USE_KEY));
    }

    /**
     * Expand an Enum rule into an `enum-class:` token, or its accepted values
     * when narrowed.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @return list<string>
     */
    private function enumTokens(Enum $rule): array
    {
        $type = $this->enumType($rule);

        if ($type === null) {
            return [];
        }

        return $this->narrowedEnumTokens($rule, $type) ?? ['enum-class:' . $type];
    }

    /**
     * Resolve the enum class an Enum rule validates against.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @return class-string<\UnitEnum>|null
     *
     * @SuppressWarnings("php:S3011")
     */
    private function enumType(Enum $rule): ?string
    {
        $type = (new \ReflectionProperty($rule, 'type'))->getValue($rule);

        return is_string($type) && enum_exists($type) ? $type : null;
    }

    /**
     * Expand a backed Enum rule narrowed by only() or except() into its type
     * and accepted values.
     *
     * The enum's component lists every case, so a narrowed rule lists its own.
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @param  class-string<\UnitEnum>  $type
     * @return list<string>|null
     */
    private function narrowedEnumTokens(Enum $rule, string $type): ?array
    {
        $accepted = is_subclass_of($type, \BackedEnum::class) ? $this->acceptedCases($rule, $type) : null;

        if ($accepted === null || count($accepted) === count($type::cases())) {
            return null;
        }

        $values = array_map(static fn (\BackedEnum $case): int|string => $case->value, $accepted);

        return [$this->backingTypeToken($type), (new EnumValuesToken)->token($values)];
    }

    /**
     * Resolve the cases an Enum rule accepts, only() winning over except().
     *
     * @param  \Illuminate\Validation\Rules\Enum  $rule
     * @param  class-string<\BackedEnum>  $type
     * @return list<\BackedEnum>|null
     *
     * @SuppressWarnings("php:S3011")
     */
    private function acceptedCases(Enum $rule, string $type): ?array
    {
        $only   = (new \ReflectionProperty($rule, 'only'))->getValue($rule);
        $except = (new \ReflectionProperty($rule, 'except'))->getValue($rule);

        if (!is_array($only) || !is_array($except)) {
            return null;
        }

        return array_values(array_filter(
            $type::cases(),
            static fn (\BackedEnum $case): bool => $only !== [] ? in_array($case, $only, true) : !in_array($case, $except, true),
        ));
    }

    /**
     * Resolve the type token for a backed enum's values.
     *
     * @param  class-string<\BackedEnum>  $type
     * @return string
     */
    private function backingTypeToken(string $type): string
    {
        return (string) (new \ReflectionEnum($type))->getBackingType() === 'int' ? 'integer' : 'string';
    }

    /**
     * Expand a PureEnum rule into an `enum-class:` token, or its accepted names
     * when narrowed.
     *
     * @param  \SineMacula\ApiToolkit\Validation\Rules\PureEnum  $rule
     * @return list<string>
     */
    private function pureEnumTokens(PureEnum $rule): array
    {
        $names = $rule->names();

        return count($names) === count($rule->enum::cases())
            ? ['enum-class:' . $rule->enum]
            : ['string', (new EnumValuesToken)->token($names)];
    }

    /**
     * Expand a Password rule into a string token plus any minimum length.
     *
     * @param  \Illuminate\Validation\Rules\Password  $rule
     * @return list<string>
     *
     * @SuppressWarnings("php:S3011")
     */
    private function passwordTokens(Password $rule): array
    {
        $min = (new \ReflectionProperty($rule, 'min'))->getValue($rule);

        return is_int($min) && $min > 0 ? ['string', 'min:' . $min] : ['string'];
    }
}
