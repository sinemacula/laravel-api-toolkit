<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Exceptions;

use SineMacula\ApiToolkit\Exceptions\ApiException;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesErrorMeta;
use SineMacula\Http\Enums\HttpStatus;
use Tests\Fixtures\Enums\AppErrorCode;

/**
 * Fixture application exception declaring the meta its error carries.
 *
 * @inheritable Extended by a fixture proving a subclass inherits the meta with
 *              the code.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
class WidgetLockedException extends ApiException implements DefinesErrorMeta
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = AppErrorCode::WIDGET_LOCKED;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::LOCKED;

    /**
     * Return the schema of the meta naming the locked widget.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function metaSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => ['widget' => ['type' => 'string']],
            'required'             => ['widget'],
            'additionalProperties' => false,
        ];
    }
}
