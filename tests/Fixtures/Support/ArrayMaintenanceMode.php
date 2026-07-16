<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Support;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/**
 * In-memory maintenance mode driver for isolated tests.
 *
 * The default file-based driver writes a process-global down file under the
 * storage path, which bleeds across parallel test workers and trips the global
 * maintenance middleware in unrelated tests. This driver keeps the state in
 * memory so activation is scoped to the binding test's application instance.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ArrayMaintenanceMode implements MaintenanceMode
{
    /** @var array<array-key, mixed>|null The active payload, or null when inactive. */
    private ?array $payload = null;

    /**
     * Activate maintenance mode.
     *
     * @param  array<array-key, mixed>  $payload
     * @return void
     */
    #[\Override]
    public function activate(array $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * Deactivate maintenance mode.
     *
     * @return void
     */
    #[\Override]
    public function deactivate(): void
    {
        $this->payload = null;
    }

    /**
     * Determine whether maintenance mode is active.
     *
     * @return bool
     */
    #[\Override]
    public function active(): bool // phpcs:ignore SineMacula.NamingConventions.BooleanMethodName.NotPredicate
    {
        return $this->payload !== null;
    }

    /**
     * Get the active maintenance mode payload.
     *
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function data(): array
    {
        return $this->payload ?? [];
    }
}
