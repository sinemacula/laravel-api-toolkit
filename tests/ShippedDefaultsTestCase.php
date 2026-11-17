<?php

declare(strict_types = 1);

namespace Tests;

/**
 * Base test case running the package on its shipped configuration, as a
 * consumer gets it on install.
 *
 * Only the database connection and cache settings stay the suite's own. Schema
 * validation is resolved from the application environment, as it is for an
 * application that leaves its variable unset.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class ShippedDefaultsTestCase extends TestCase
{
    /**
     * Define the environment setup.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        /** @var string $environment */
        $environment = $app['env'];

        $config->set('api-toolkit', $this->shippedConfiguration([
            'APP_ENV'                                => $environment,
            'API_TOOLKIT_RESOURCES_VALIDATE_SCHEMAS' => null,
        ]));
    }

    /**
     * Load the shipped config file with the given environment variables, null
     * leaving one unset, restoring the environment afterwards.
     *
     * @param  array<string, string|null>  $variables
     * @return array<array-key, mixed>
     */
    protected function shippedConfiguration(array $variables): array
    {
        $server  = $_SERVER;
        $env     = $_ENV;
        $process = array_map(getenv(...), array_keys($variables));

        foreach ($variables as $name => $value) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($value === null ? $name : $name . '=' . $value);

            if ($value === null) {
                continue;
            }

            $_SERVER[$name] = $value;
            $_ENV[$name]    = $value;
        }

        try {
            $config = require dirname(__DIR__) . '/config/api-toolkit.php';

            static::assertIsArray($config);

            return $config;
        } finally {
            $_SERVER = $server;
            $_ENV    = $env;

            foreach (array_combine(array_keys($variables), $process) as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}
