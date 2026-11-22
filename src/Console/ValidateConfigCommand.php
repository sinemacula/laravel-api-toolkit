<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use SineMacula\ApiToolkit\Config\ToolkitConfigValidator;
use SineMacula\ApiToolkit\Exceptions\InvalidConfigurationException;

/**
 * Artisan command to validate every toolkit setting.
 *
 * Runs the check applied at boot, so CI or a deploy step can catch a setting
 * that would stop every web and queue worker.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateConfigCommand extends Command
{
    /** @var string The console command signature */
    protected $signature = 'api-toolkit:config:validate';

    /** @var string The console command description */
    protected $description = 'Validate every toolkit setting';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            ToolkitConfigValidator::validate();
        } catch (InvalidConfigurationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Toolkit settings are valid.');

        return self::SUCCESS;
    }
}
