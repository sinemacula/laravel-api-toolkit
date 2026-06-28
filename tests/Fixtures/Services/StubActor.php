<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services;

use SineMacula\ApiToolkit\Services\Contracts\Actor;

/**
 * Serialisable Actor stub for ServiceContext round-trip tests.
 *
 * Named (not anonymous) so that PHP can serialise it through the queue.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class StubActor implements Actor
{
    /**
     * Return the unique identifier for this actor.
     *
     * @return string
     */
    #[\Override]
    public function actorIdentifier(): string
    {
        return 'stub-id';
    }

    /**
     * Return the type string for this actor.
     *
     * @return string
     */
    #[\Override]
    public function actorType(): string
    {
        return 'stub';
    }

    /**
     * Return the human-readable label for this actor.
     *
     * @return string
     */
    #[\Override]
    public function actorLabel(): string
    {
        return 'Stub Actor';
    }

    /**
     * Return null - this stub has no Authenticatable backing.
     *
     * @return null
     */
    #[\Override]
    public function toAuthenticatable(): null
    {
        return null;
    }
}
