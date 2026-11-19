<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest whose authorization denies with a message.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DeniedFormRequest extends FormRequest
{
    /** The message the request is denied with */
    public const string DENIAL = 'Only the owner may edit this order.';

    /**
     * Deny the request.
     *
     * @return \Illuminate\Auth\Access\Response
     */
    public function authorize(): Response
    {
        return Response::deny(self::DENIAL);
    }
}
