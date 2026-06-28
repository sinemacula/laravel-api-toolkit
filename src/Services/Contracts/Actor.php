<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Explicit-causer contract.
 *
 * Represents the identity that initiated a service action. Implementers
 * may be authenticated users, system processes, or anonymous callers.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Actor
{
    /**
     * Return the unique identifier for this actor.
     *
     * Returns null for system or anonymous actors that have no
     * persistent identity.
     *
     * @return int|string|null
     */
    public function actorIdentifier(): int|string|null;

    /**
     * Return the type string for this actor.
     *
     * Typically a morph alias, 'system', or 'anonymous'.
     *
     * @return string
     */
    public function actorType(): string;

    /**
     * Return the human-readable label for this actor.
     *
     * The label is snapshotted at capture time and must not change
     * after the actor is constructed.
     *
     * @return string
     */
    public function actorLabel(): string;

    /**
     * Return the Authenticatable instance for this actor.
     *
     * Used by Gate::forUser() to perform authorisation checks on behalf
     * of the actor. Returns null for system or anonymous actors.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function toAuthenticatable(): ?Authenticatable;
}
