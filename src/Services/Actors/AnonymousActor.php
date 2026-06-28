<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Actors;

use SineMacula\ApiToolkit\Services\Contracts\Actor;

/**
 * Unauthenticated-caller actor.
 *
 * Represents a public API caller with no authenticated identity. Distinct from
 * SystemActor: anonymous callers originate from outside the system, whereas
 * system actors are internal processes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AnonymousActor implements Actor
{
    /**
     * Return null - anonymous actors have no persistent identity.
     *
     * @return null
     */
    #[\Override]
    public function actorIdentifier(): null
    {
        return null;
    }

    /**
     * Return the type string for this actor.
     *
     * @return string
     */
    #[\Override]
    public function actorType(): string
    {
        return 'anonymous';
    }

    /**
     * Return the human-readable label for this actor.
     *
     * @return string
     */
    #[\Override]
    public function actorLabel(): string
    {
        return 'Anonymous';
    }

    /**
     * Return null - anonymous actors have no Authenticatable backing.
     *
     * @return null
     */
    #[\Override]
    public function toAuthenticatable(): null
    {
        return null;
    }
}
