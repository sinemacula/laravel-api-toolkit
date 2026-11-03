<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Services\Input;

use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Extendable payload fixture whose constructor promotes a sensitive field.
 *
 * Lets a test subclass inherit or redeclare the constructor, proving the marker
 * is honoured on properties promoted by a parent constructor.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class SensitiveBaseInput extends Payload
{
    /**
     * Create a new SensitiveBaseInput instance.
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
