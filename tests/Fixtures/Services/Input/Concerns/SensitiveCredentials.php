<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input\Concerns;

/**
 * Trait fixture whose constructor promotes a sensitive field.
 *
 * Lets a payload take the promoted properties from a trait, either through the
 * trait constructor directly or through an alias called by its own constructor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
trait SensitiveCredentials
{
    /**
     * Promote the username and the sensitive password.
     *
     * @param  string  $username
     * @param  string  $password
     */
    public function __construct(

        /** The username; exposed verbatim in the summary. */
        public readonly string $username = '',

        /** The password; left out of the summary. */
        #[\SensitiveParameter]
        public readonly string $password = '',
    ) {}
}
