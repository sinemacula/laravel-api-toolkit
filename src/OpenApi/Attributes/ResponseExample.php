<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Attributes;

use SineMacula\Http\Enums\HttpStatus;

/**
 * Declare a named example of an action's success response body, read from a
 * JSON file holding the body as sent.
 *
 * The file is relative to `<docs_path>/examples/`; an audience's copy under
 * `audiences/<name>/examples/` replaces it for that audience. Only a body
 * declared as DefinesSchema shapes takes one, since every other body's examples
 * are generated.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final readonly class ResponseExample
{
    /** The message for a status outside the success range */
    private const string STATUS_MESSAGE = 'A response example must name a success status, [%d] given; error examples come from the exceptions an action throws';

    /** The message for a name exported as a list index rather than a map key */
    private const string INTEGER_NAME_MESSAGE = 'A response example name must not be an integer, [%s] given';

    /** The message for a file that is not a relative path to a visible .json file */
    private const string FILE_MESSAGE = 'A response example file must be a relative path to a visible .json file inside the examples directory, [%s] given';

    /**
     * Create a new response-example directive.
     *
     * @param  \SineMacula\Http\Enums\HttpStatus  $status
     * @param  string  $name
     * @param  string  $file
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(

        /** The success status whose body the example shows. */
        public HttpStatus $status,

        /** The name the example is exported under. */
        public string $name,

        /** The path of the example's JSON file, relative to the examples directory. */
        public string $file,
    ) {
        if (!$status->isSuccess()) {
            throw new \InvalidArgumentException(sprintf(self::STATUS_MESSAGE, $status->value));
        }

        if ($name === '') {
            throw new \InvalidArgumentException('A response example must have a name');
        }

        if ((string) (int) $name === $name) {
            throw new \InvalidArgumentException(sprintf(self::INTEGER_NAME_MESSAGE, $name));
        }

        if (!self::isRelativeJsonPath($file)) {
            throw new \InvalidArgumentException(sprintf(self::FILE_MESSAGE, $file));
        }
    }

    /**
     * Determine whether the path names a visible .json file beneath the
     * directory it is resolved against, without leaving it.
     *
     * @param  string  $file
     * @return bool
     */
    private static function isRelativeJsonPath(string $file): bool
    {
        $hidden = array_filter(explode('/', $file), static fn (string $segment): bool => $segment === '' || str_starts_with($segment, '.'));

        return str_ends_with($file, '.json')
            && !str_contains($file, '\\')
            && $hidden === [];
    }
}
