<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

/**
 * Keeps an integer version column that every insert sets to 1 and every update
 * advances by one.
 *
 * The column is written inside the insert and the update themselves, after the
 * model events, so quiet saves advance it too. A clean save writes nothing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait HasVersion
{
    /**
     * Get the name of the column holding the version.
     *
     * @return string
     */
    public function getVersionColumn(): string
    {
        $column = defined(static::class . '::VERSION') ? constant(static::class . '::VERSION') : null;

        return is_string($column) ? $column : 'version';
    }

    /**
     * Advance the version, so the next save writes it even when no column has
     * changed.
     *
     * @return void
     *
     * @throws \LogicException
     */
    public function markVersionChanged(): void
    {
        $this->attributes[$this->getVersionColumn()] = $this->nextVersion();
    }

    /**
     * Get the attributes to insert, starting the version at 1.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getAttributesForInsert(): array
    {
        $this->attributes[$this->getVersionColumn()] = 1;

        return parent::getAttributesForInsert();
    }

    /**
     * Get the attributes to update, advancing the version over any value
     * assigned to it.
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException
     */
    #[\Override]
    protected function getDirtyForUpdate(): array
    {
        $dirty = parent::getDirtyForUpdate();

        if ($dirty === []) {
            return $dirty;
        }

        $column = $this->getVersionColumn();

        $this->attributes[$column] = $dirty[$column] = $this->nextVersion();

        return $dirty;
    }

    /**
     * Compute the version after the stored one.
     *
     * @return int
     *
     * @throws \LogicException
     */
    private function nextVersion(): int
    {
        $column  = $this->getVersionColumn();
        $current = $this->getRawOriginal($column);

        if (!is_numeric($current)) {
            throw new \LogicException(sprintf('%s cannot advance its [%s] version, as the stored version was not loaded or is null.', static::class, $column));
        }

        return (int) $current + 1;
    }
}
