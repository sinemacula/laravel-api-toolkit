<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema;

use SineMacula\ApiToolkit\Schema\Concerns\HasMetricModifiers;

/**
 * Count schema helper for metric definitions.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class Count extends BaseDefinition
{
    use HasMetricModifiers;

    /**
     * Prevent direct instantiation.
     *
     * @param  string  $name
     * @param  string|null  $alias
     */
    private function __construct(

        /** Canonical metric key (typically a relation alias) */
        private readonly string $name,

        // Optional alias to expose this metric under
        ?string $alias = null,
    ) {
        $this->alias = $alias;
    }

    /**
     * Define a count metric by key.
     *
     * @param  string  $key
     * @param  string|null  $alias
     * @return self
     */
    public static function of(string $key, ?string $alias = null): self
    {
        return new self($key, $alias);
    }

    /**
     * Convert this definition to a normalized array.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function toArray(): array
    {
        $present = $this->alias ?? $this->name;
        $key     = '__count__:' . $present;

        return [
            $key => array_filter([
                'key'        => $present,
                'metric'     => 'count',
                'relation'   => $this->name,
                'constraint' => $this->constraint,
                'default'    => $this->isDefault ? true : null,
                'extras'     => $this->extras ?: null,
                ...$this->commonAttributes(),
            ], static fn ($value) => $value !== null),
        ];
    }
}
