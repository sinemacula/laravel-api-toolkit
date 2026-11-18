<?php

declare(strict_types = 1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration)
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    // Guaranteed by the laravel/framework constraint
    ->ignoreErrorsOnExtensions(['ext-ctype', 'ext-filter', 'ext-mbstring', 'ext-tokenizer'], [ErrorType::SHADOW_DEPENDENCY])
    ->ignoreErrorsOnPackages(['nesbot/carbon', 'symfony/http-foundation', 'symfony/http-kernel'], [ErrorType::SHADOW_DEPENDENCY])
    // Optional integrations, used only when installed
    ->ignoreErrorsOnPackages(['laravel/octane', 'sinemacula/laravel-resource-exporter'], [ErrorType::DEV_DEPENDENCY_IN_PROD]);
