<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Concerns;

/**
 * Keeps an integer version column that every insert sets to 1 and every update
 * advances by one.
 *
 * The column is written inside the insert and the update themselves, after the
 * model events, so quiet saves advance it too. A clean save writes nothing. A
 * write from a created, updated or saved listener advances from the version
 * just written, before the model syncs its original state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait HasVersion
{
    /** @var int|null The version written since the original state last synced */
    private ?int $writtenVersion = null;

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
     * Sync the original state, which then holds any version this instance
     * wrote.
     *
     * @return static
     */
    #[\Override]
    public function syncOriginal(): static
    {
        $this->writtenVersion = null;

        return parent::syncOriginal();
    }

    /**
     * Get the attributes to insert, starting the version at 1.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getAttributesForInsert(): array
    {
        $this->attributes[$this->getVersionColumn()] = $this->writtenVersion = 1;

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
            return [];
        }

        $column = $this->getVersionColumn();

        $this->attributes[$column] = $dirty[$column] = $this->writtenVersion = $this->nextVersion();

        return $dirty;
    }

    /**
     * Compute the version after the stored one, or after the one this instance
     * wrote since it last synced when that is later.
     *
     * @return int
     *
     * @throws \LogicException
     */
    private function nextVersion(): int
    {
        $column   = $this->getVersionColumn();
        $stored   = $this->getRawOriginal($column);
        $versions = array_filter([is_numeric($stored) ? (int) $stored : null, $this->writtenVersion], is_int(...));

        if ($versions === []) {
            throw new \LogicException(sprintf('%s cannot advance its [%s] version, as the stored version was not loaded or is null.', static::class, $column));
        }

        return max($versions) + 1;
    }
}
