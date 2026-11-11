<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Services\Actors;

use SineMacula\ApiToolkit\Services\Contracts\Actor;

/**
 * Actor for system processes and scheduled jobs.
 *
 * The service runner skips authorisation for this actor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class SystemActor implements Actor
{
    /**
     * Create a new SystemActor instance.
     *
     * @param  string  $label
     */
    public function __construct(

        /** Human-readable label for this system context */
        private string $label = 'System',
    ) {}

    /**
     * Create a SystemActor with a custom label.
     *
     * @param  string  $label
     * @return self
     */
    public static function named(string $label): self
    {
        return new self($label);
    }

    /**
     * Return null - system actors have no persistent identity.
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
        return 'system';
    }

    /**
     * Return the human-readable label for this system context.
     *
     * @return string
     */
    #[\Override]
    public function actorLabel(): string
    {
        return $this->label;
    }

    /**
     * Return null - system actors bypass authentication.
     *
     * @return null
     */
    #[\Override]
    public function toAuthenticatable(): null
    {
        return null;
    }
}
