<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use Illuminate\Validation\Rule;
use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Fixture payload updating the fields of an article it was sent.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ArticleUpdatePayload extends Payload
{
    /**
     * Create a new ArticleUpdatePayload instance.
     *
     * @param  string|null  $title
     * @param  string|null  $body
     * @param  string|null  $summary
     * @param  string|null  $status
     * @param  list<int>|null  $tags
     */
    public function __construct(

        /** The title, if one was sent */
        public readonly ?string $title = null,

        /** The body, if one was sent */
        public readonly ?string $body = null,

        /** The summary, if one was sent */
        public readonly ?string $summary = null,

        /** The publication status, if one was sent */
        public readonly ?string $status = null,

        /** The keys of the tags to sync, if any were sent */
        public readonly ?array $tags = null,
    ) {}

    /**
     * Return the Laravel validation rules for this input.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public static function rules(): array
    {
        return [
            'title'   => ['sometimes', 'string'],
            'body'    => ['sometimes', 'string'],
            'summary' => ['sometimes', 'string'],
            'status'  => ['sometimes', Rule::in(['draft', 'published'])],
            'tags'    => ['sometimes', 'array'],
            'tags.*'  => ['integer', Rule::exists('tags', 'id')],
        ];
    }
}
