<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Schema\Concerns;

/**
 * Declares a relation or aggregate sortable through a correlated subquery.
 *
 * No index can serve such an order, so the reason it may run unindexed is part
 * of the declaration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait DeclaresUnindexedSort
{
    /** @var string|null Why the order may run unindexed; null while not sortable */
    private ?string $sortReason = null;

    /**
     * Declare this definition sortable, recording why its order needs no index.
     *
     * @param  string  $reason
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function sortable(string $reason): static
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A sortable relation or aggregate must carry the reason its order is unindexed');
        }

        $this->sortReason = trim($reason);

        return $this;
    }

    /**
     * Return the sort attributes for toArray(), or none while not sortable.
     *
     * @return array<string, bool|string>
     */
    private function sortAttributes(): array
    {
        return $this->sortReason === null ? [] : ['sortable' => true, 'unindexed' => $this->sortReason];
    }
}
