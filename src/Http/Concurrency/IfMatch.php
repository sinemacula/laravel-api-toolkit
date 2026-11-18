<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Concurrency;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use SineMacula\ApiToolkit\Services\ServiceContext;
use SineMacula\Http\Enums\HttpHeader;

/**
 * The entity tags an If-Match precondition accepts.
 *
 * Tags compare weakly, ignoring W/ on either side, where RFC 9110 compares them
 * strongly: the toolkit's tags are weak, and proxies weaken strong ones. A
 * malformed field accepts nothing, so a client error fails closed.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class IfMatch
{
    /** The pattern of a well-formed list of entity tags */
    private const string TAG_LIST = '/\A[ \t,]*(?:(?:W\/)?"[\x21\x23-\x7E\x80-\xFF]*"[ \t]*(?:,[ \t,]*|\z))+\z/';

    /** The pattern of one entity tag, capturing its opaque tag */
    private const string TAG = '/(?:W\/)?"([^"]*)"/';

    /**
     * Constructor.
     *
     * @param  bool  $wildcard
     * @param  list<string>  $tags
     */
    private function __construct(

        /** Whether the field is "*", accepting any current state. */
        private bool $wildcard,

        /** The accepted tags, each in its weak form. */
        private array $tags,
    ) {}

    /**
     * Read the request's If-Match field, or null when it sends none.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return self|null
     */
    public static function fromRequest(Request $request): ?self
    {
        $value = self::headerValue($request);

        return $value === null ? null : self::parse($value);
    }

    /**
     * Read the If-Match field the context captured from its request, or null
     * when it captured none.
     *
     * @param  \SineMacula\ApiToolkit\Services\ServiceContext  $context
     * @return self|null
     */
    public static function fromContext(ServiceContext $context): ?self
    {
        $value = $context->metadata[ServiceContext::METADATA_IF_MATCH] ?? null;

        return match (true) {
            $value === null   => null,
            is_string($value) => self::parse($value),
            default           => new self(false, []),
        };
    }

    /**
     * Join the request's If-Match field lines into one value, or return null
     * when it sends none.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public static function headerValue(Request $request): ?string
    {
        $lines = $request->headers->all(HttpHeader::IF_MATCH->getName());

        return $lines === [] ? null : implode(',', $lines);
    }

    /**
     * Determine whether the model's current state satisfies the precondition.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function matches(Model $model): bool
    {
        return $this->wildcard || in_array(EntityTag::for($model), $this->tags, true);
    }

    /**
     * Parse a field value into the tags it accepts.
     *
     * @param  string  $value
     * @return self
     */
    private static function parse(string $value): self
    {
        if (trim($value, " \t") === '*') {
            return new self(true, []);
        }

        if (preg_match(self::TAG_LIST, $value) !== 1) {
            return new self(false, []);
        }

        preg_match_all(self::TAG, $value, $matches);

        return new self(false, array_map(static fn (string $opaque): string => sprintf('W/"%s"', $opaque), $matches[1]));
    }
}
