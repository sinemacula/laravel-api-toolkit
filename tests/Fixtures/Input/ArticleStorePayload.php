<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Input;

use Illuminate\Validation\Rule;
use SineMacula\ApiToolkit\Services\Input\Payload;

/**
 * Fixture payload creating an article and the tags it carries.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ArticleStorePayload extends Payload
{
    /**
     * Create a new ArticleStorePayload instance.
     *
     * @param  string  $title
     * @param  string  $slug
     * @param  string  $body
     * @param  string  $summary
     * @param  string  $status
     * @param  list<int>  $tags
     */
    public function __construct(

        /** The title */
        public readonly string $title,

        /** The unique slug */
        public readonly string $slug,

        /** The body */
        public readonly string $body,

        /** The summary */
        public readonly string $summary,

        /** The publication status */
        public readonly string $status = 'draft',

        /** The keys of the tags to attach */
        public readonly array $tags = [],
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
            'title'   => ['required', 'string'],
            'slug'    => ['required', 'string', Rule::unique('articles', 'slug')],
            'body'    => ['required', 'string'],
            'summary' => ['required', 'string'],
            'status'  => ['sometimes', Rule::in(['draft', 'published'])],
            'tags'    => ['sometimes', 'array'],
            'tags.*'  => ['integer', Rule::exists('tags', 'id')],
        ];
    }
}
