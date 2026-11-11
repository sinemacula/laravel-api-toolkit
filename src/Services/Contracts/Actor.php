<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The identity that initiated a service action.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
interface Actor
{
    /**
     * Return the unique identifier for this actor, if it has one.
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
     * Must not change after the actor is constructed.
     *
     * @return string
     */
    public function actorLabel(): string;

    /**
     * Return the Authenticatable instance for this actor, if it has one.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function toAuthenticatable(): ?Authenticatable;
}
