<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use SineMacula\ApiToolkit\Enums\ErrorCode;
use SineMacula\ApiToolkit\OpenApi\Contracts\DefinesErrorMeta;
use SineMacula\Http\Enums\HttpStatus;

/**
 * Invalid input exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InvalidInputException extends ApiException implements DefinesErrorMeta
{
    /** @var \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface The internal error code */
    public const \SineMacula\ApiToolkit\Contracts\ErrorCodeInterface CODE = ErrorCode::INVALID_INPUT;

    /** @var \SineMacula\Http\Enums\HttpStatus The HTTP status code */
    public const HttpStatus HTTP_STATUS = HttpStatus::UNPROCESSABLE_CONTENT;

    /**
     * Return the schema of the meta, the messages keyed by each failed field.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function metaSchema(): array
    {
        return [
            'type'                 => 'object',
            'minProperties'        => 1,
            'additionalProperties' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
        ];
    }
}
