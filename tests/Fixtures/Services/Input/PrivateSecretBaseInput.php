<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Extendable payload fixture whose constructor promotes a private sensitive
 * field, which a subclass carries but cannot see.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class PrivateSecretBaseInput extends Payload
{
    /**
     * Create a new PrivateSecretBaseInput instance.
     *
     * @param  string  $secret
     */
    public function __construct(

        /** The secret; private to this class. */
        #[\SensitiveParameter]
        private readonly string $secret = '',
    ) {}

    /**
     * Return the secret.
     *
     * @return string
     */
    public function secret(): string
    {
        return $this->secret;
    }
}
