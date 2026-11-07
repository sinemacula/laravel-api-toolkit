<?php

namespace Tests\Fixtures\Services;

/**
 * Ordered log of what happened while a service ran.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class ServiceLog
{
    /** @var list<string> The recorded entries */
    public array $entries = [];

    /**
     * Record an entry.
     *
     * @param  string  $entry
     * @return void
     */
    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}
