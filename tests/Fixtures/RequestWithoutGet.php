<?php

namespace Tests\Fixtures;

use Illuminate\Http\Request;
use LogicException;

/**
 * Request that fails when the deprecated get() method is called.
 *
 * The toolkit's locked Symfony version predates the get() deprecation, so a
 * deprecation listener would not catch a regression here.
 *
 * @method bool includeTrashed()
 * @method bool onlyTrashed()
 *
 * @author      Michael Stivala <michaelstivala@me.com>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
class RequestWithoutGet extends Request
{
    /**
     * Fail the test when get() is called.
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        throw new LogicException('Request::get() is deprecated');
    }
}
