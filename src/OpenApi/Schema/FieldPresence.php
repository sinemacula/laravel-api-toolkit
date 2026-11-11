<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Schema;

/**
 * Reads how a field's normalised rule tokens govern its presence.
 *
 * Laravel runs required and present rules on a missing field unless sometimes
 * skips it or an exclude rule drops it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class FieldPresence
{
    /**
     * Determine whether the field is required.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    public function isRequired(array $tokens): bool
    {
        return ($this->hasToken($tokens, 'required') || $this->hasToken($tokens, 'present'))
            && !$this->hasToken($tokens, 'sometimes')
            && !$this->isExcludable($tokens);
    }

    /**
     * Determine whether a sent value of the field must not be empty.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    public function rejectsEmpty(array $tokens): bool
    {
        return ($this->hasToken($tokens, 'required') || $this->hasToken($tokens, 'filled')) && !$this->isExcludable($tokens);
    }

    /**
     * Determine whether the field accepts null.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    public function acceptsNull(array $tokens): bool
    {
        return $this->hasToken($tokens, 'nullable') && !$this->rejectsEmpty($tokens);
    }

    /**
     * Determine whether a field's rules refuse every body missing the given
     * ancestor.
     *
     * @param  list<string>  $tokens
     * @param  string  $ancestor
     * @return bool
     */
    public function demandsAncestor(array $tokens, string $ancestor): bool
    {
        return $this->demandsWhenMissing($tokens, static fn (string $path): bool => $path === $ancestor || str_starts_with($path, $ancestor . '.'));
    }

    /**
     * Determine whether a field's rules refuse every body in which the given
     * ancestor holds no named key, as a list does.
     *
     * @param  list<string>  $tokens
     * @param  string  $ancestor
     * @return bool
     */
    public function demandsNamedKey(array $tokens, string $ancestor): bool
    {
        $prefix = $ancestor . '.';

        return $this->demandsWhenMissing($tokens, static function (string $path) use ($prefix): bool {
            $key = explode('.', substr($path, strlen($prefix)))[0];

            return str_starts_with($path, $prefix) && (string) (int) $key !== $key;
        });
    }

    /**
     * Determine whether any exclude rule may drop the field from validation.
     *
     * @param  list<string>  $tokens
     * @return bool
     */
    public function isExcludable(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (str_starts_with($token, 'exclude')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a field's rules refuse every body missing the fields
     * the test picks out.
     *
     * @param  list<string>  $tokens
     * @param  \Closure(string): bool  $missing
     * @return bool
     */
    private function demandsWhenMissing(array $tokens, \Closure $missing): bool
    {
        if ($this->hasToken($tokens, 'sometimes') || $this->isExcludable($tokens)) {
            return false;
        }

        foreach ($tokens as $token) {
            [$rule, $arguments] = $this->parse($token);

            $absent = array_filter($arguments, $missing);

            $demands = match ($rule) {
                'required', 'present'  => true,
                'required_without'     => $absent !== [],
                'required_without_all' => count($absent) === count($arguments),
                default                => false,
            };

            if ($demands) {
                return true;
            }
        }

        return false;
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
            if ($this->parse($token)[0] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a rule token into its rule name and arguments as the validator
     * does.
     *
     * @param  string  $token
     * @return array{0: string, 1: list<string>}
     */
    private function parse(string $token): array
    {
        $parts     = explode(':', $token, 2);
        $arguments = str_getcsv($parts[1] ?? '', ',', '"', '\\');

        return [$parts[0], array_map(static fn (?string $argument): string => (string) $argument, $arguments)];
    }
}
