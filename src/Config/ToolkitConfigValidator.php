<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Config;

use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\ExceptionMap;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;
use SineMacula\ApiToolkit\Http\Resources\ResourceDiscovery;
use SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException;
use SineMacula\ApiToolkit\OpenApi\Resolution\ServerListValidator;
use SineMacula\ApiToolkit\Query\QueryCostLimits;

/**
 * Validates every toolkit setting at once: each read, the checks across
 * settings and the keys the toolkit does not read.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class ToolkitConfigValidator
{
    /**
     * Validate every setting, failing with one exception listing each problem.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     */
    public static function validate(): void
    {
        $problems = [];

        foreach ([...self::readChecks(), ...self::crossChecks()] as $check) {
            try {
                $check();
            } catch (InvalidConfigurationException $exception) {
                $problems[] = $exception->problem;
            } catch (ServerConfigurationException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        $configured = Config::get(ToolkitConfig::ROOT);

        $problems = is_array($configured)
            ? [...$problems, ...self::unknownKeys(ToolkitConfig::defaults(), $configured, '')]
            : [InvalidConfigurationException::mustBe(ToolkitConfig::ROOT, ToolkitConfig::AN_ARRAY, $configured)->problem];

        if ($problems !== []) {
            throw InvalidConfigurationException::invalid($problems);
        }
    }

    /**
     * List the reads of every setting checked on its own, including those a
     * check below reads, so one setting's failure cannot hide another's.
     *
     * @return list<callable(): mixed>
     */
    private static function readChecks(): array
    {
        return [
            LifecycleSettings::cachePrefix(...),
            LifecycleSettings::cacheRetryAfter(...),
            HttpSettings::renderStrategy(...),
            HttpSettings::shouldIncludeDebugInfo(...),
            ResourceSettings::shouldMapMorphTypes(...),
            ResourceSettings::shouldValidateSchemas(...),
            ResourceSettings::fixedFields(...),
            ResourceSettings::sensitiveColumns(...),
            ResourceSettings::shouldNarrowColumns(...),
            ResourceSettings::relationLimit(...),
            OpenApiSettings::output(...),
            OpenApiSettings::docsPath(...),
            OpenApiSettings::defaultAudience(...),
            OpenApiSettings::info(...),
            OpenApiSettings::shouldExportOperationIds(...),
            OpenApiSettings::tags(...),
            OpenApiSettings::securityDrivers(...),
            OpenApiSettings::excludedNamespaces(...),
            OpenApiSettings::exceptionNamespaces(...),
            HttpSettings::parserAlias(...),
            HttpSettings::shouldRegisterParserMiddleware(...),
            QuerySettings::maxLimit(...),
            QuerySettings::defaultLimit(...),
            QuerySettings::canOrderRandomly(...),
            QuerySettings::relationCacheTtl(...),
            ...array_map(static fn (string $cap): \Closure => static fn (): int => QuerySettings::costCap($cap), QueryCostLimits::NAMES),
            QuerySettings::minWordLength(...),
            QuerySettings::maxSearchLength(...),
            QuerySettings::maxSearchWords(...),
            QuerySettings::indexProofTtl(...),
            QuerySettings::unverifiedConnections(...),
            LifecycleSettings::chunkSize(...),
            LifecycleSettings::poolLimit(...),
            LifecycleSettings::flushStrategy(...),
            LifecycleSettings::isFlushTransactional(...),
            LifecycleSettings::shouldRethrowAtBoundary(...),
            LifecycleSettings::shouldInvalidateQueryCache(...),
            LifecycleSettings::shouldInvalidateOnMigrations(...),
            HttpSettings::correlationHeader(...),
            LifecycleSettings::shouldLogNotifications(...),
            LifecycleSettings::excludedNotifications(...),
            LifecycleSettings::notificationLogChannel(...),
            HttpSettings::shouldSwapMaintenanceMode(...),
            HttpSettings::shouldPrettyPrintJson(...),
            HttpSettings::jsonPrettyPrintScope(...),
            HttpSettings::shouldRegisterThrottle(...),
            HttpSettings::maintenanceExcept(...),
        ];
    }

    /**
     * List the checks of settings that name classes, paths or other settings.
     *
     * @return list<callable(): mixed>
     */
    private static function crossChecks(): array
    {
        return [
            ExceptionMap::fromConfig(...),
            self::checkResourcePaths(...),
            ResourceSettings::bindings(...),
            static fn (): array => (new ServerListValidator)->validate(OpenApiSettings::servers()),
            self::checkAudienceServers(...),
            self::checkDefaultAudience(...),
            self::checkDefaultLimit(...),
            self::checkThrottleClass(...),
        ];
    }

    /**
     * Check that every configured resource path is a directory.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     *
     * @SuppressWarnings("php:S1144")
     */
    private static function checkResourcePaths(): void
    {
        foreach (ResourceSettings::paths() ?? [] as $index => $path) {
            if (!is_dir($path)) {
                throw InvalidConfigurationException::mustBe(ToolkitConfig::key('resources.paths.' . $index), 'an existing directory', $path);
            }
        }
    }

    /**
     * Check that the servers of every audience are valid.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\OpenApi\Exceptions\ServerConfigurationException
     *
     * @SuppressWarnings("php:S1144")
     */
    private static function checkAudienceServers(): void
    {
        foreach (OpenApiSettings::audiences() as $name => $audience) {

            if (!array_key_exists('servers', $audience)) {
                continue;
            }

            (new ServerListValidator)->validate($audience['servers'], (string) $name);
        }
    }

    /**
     * Check that the default audience is a declared one.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     *
     * @SuppressWarnings("php:S1144")
     */
    private static function checkDefaultAudience(): void
    {
        $default = OpenApiSettings::defaultAudience();

        if (!array_key_exists($default, OpenApiSettings::audiences())) {
            $expectation = 'an audience declared in ' . ToolkitConfig::key('openapi.audiences');

            throw InvalidConfigurationException::mustBe(ToolkitConfig::key('openapi.default_audience'), $expectation, $default);
        }
    }

    /**
     * Check that the default page size is within the page size ceiling.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     *
     * @SuppressWarnings("php:S1144")
     */
    private static function checkDefaultLimit(): void
    {
        $default = QuerySettings::defaultLimit();
        $ceiling = QuerySettings::maxLimit();

        if ($ceiling > 0 && $default > $ceiling) {
            $expectation = sprintf('at most the %s of %d', ToolkitConfig::key('parser.max_limit'), $ceiling);

            throw InvalidConfigurationException::mustBe(ToolkitConfig::key('parser.defaults.limit'), $expectation, $default);
        }
    }

    /**
     * Check that the configured throttle middleware class exists.
     *
     * @return void
     *
     * @throws \SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException
     *
     * @SuppressWarnings("php:S1144")
     */
    private static function checkThrottleClass(): void
    {
        $class = HttpSettings::throttleClass();

        if ($class !== null && !class_exists($class)) {
            throw InvalidConfigurationException::mustBe(ToolkitConfig::key('middleware.throttle.class'), 'an existing class, or null', $class);
        }
    }

    /**
     * List a problem for every configured setting the toolkit does not ship.
     *
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $configured
     * @param  string  $path
     * @return list<string>
     */
    private static function unknownKeys(array $defaults, array $configured, string $path): array
    {
        $problems = [];

        foreach ($configured as $name => $value) {

            $child = ltrim($path . '.' . $name, '.');

            if (in_array(ToolkitConfig::key($child), ResourceDiscovery::INTERNAL_KEYS, true)) {
                continue;
            }

            if (!array_key_exists($name, $defaults)) {
                $problems[] = InvalidConfigurationException::unknown(ToolkitConfig::key($child))->problem;
            } elseif (ToolkitConfig::isSection($child, $defaults[$name]) && is_array($value)) {
                $problems = [...$problems, ...self::unknownKeys($defaults[$name], $value, $child)];
            }
        }

        return $problems;
    }
}
