<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Exceptions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\CachesConfiguration;

/**
 * Exception thrown when a toolkit setting is missing, unknown or invalid.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InvalidConfigurationException extends \LogicException
{
    /**
     * Constructor.
     *
     * @param  string  $problem
     * @param  string  $message
     * @param  \Throwable|null  $previous
     */
    private function __construct(

        /** The problem, without the advice on a cached configuration */
        public readonly string $problem,

        // The problem, with that advice when the configuration is cached
        string $message,

        // The error the problem was found through
        ?\Throwable $previous,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Create an exception for a setting holding a value it does not accept.
     *
     * @param  string  $key
     * @param  string  $expectation
     * @param  mixed  $actual
     * @return self
     */
    public static function mustBe(string $key, string $expectation, mixed $actual): self
    {
        return self::because(sprintf('The %s setting must be %s; [%s] given.', $key, $expectation, self::describe($actual)));
    }

    /**
     * Create an exception for a setting that is not set.
     *
     * @param  string  $key
     * @return self
     */
    public static function missing(string $key): self
    {
        return self::because(sprintf('The %s setting is missing; add it from the published config.', $key));
    }

    /**
     * Create an exception for a setting the toolkit does not read.
     *
     * @param  string  $key
     * @return self
     */
    public static function unknown(string $key): self
    {
        return self::because(sprintf('The %s setting is not a toolkit setting; remove it or correct its name.', $key));
    }

    /**
     * Create one exception listing every given problem.
     *
     * @param  list<string>  $problems
     * @return self
     */
    public static function invalid(array $problems): self
    {
        return self::because(implode(' ', $problems));
    }

    /**
     * Create an exception with the given message, telling how to rebuild a
     * cached configuration.
     *
     * @param  string  $message
     * @param  \Throwable|null  $previous
     * @return self
     */
    public static function because(string $message, ?\Throwable $previous = null): self
    {
        $app = Container::getInstance();

        if (!$app instanceof CachesConfiguration || !$app->configurationIsCached()) {
            return new self($message, $message, $previous);
        }

        return new self($message, sprintf('%s The configuration is cached; delete %s and rebuild it.', $message, $app->getCachedConfigPath()), $previous);
    }

    /**
     * Describe the given value for a message.
     *
     * @param  mixed  $value
     * @return string
     */
    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value)                => '\'' . $value . '\'',
            is_bool($value)                  => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default                          => get_debug_type($value),
        };
    }
}
