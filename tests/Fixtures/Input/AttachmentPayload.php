<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use Illuminate\Http\UploadedFile;
use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Payload fixture that requires an uploaded file.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class AttachmentPayload extends Payload
{
    /**
     * Create a new AttachmentPayload instance.
     *
     * @param  \Illuminate\Http\UploadedFile  $attachment
     */
    public function __construct(

        /** The uploaded file; required and validated as a file. */
        public readonly UploadedFile $attachment,
    ) {}

    /**
     * Return the Laravel validation rules for this input.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return ['attachment' => ['required', 'file']];
    }
}
