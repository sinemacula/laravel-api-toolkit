<?php

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Services\SchemaValidator;

/**
 * Artisan command to validate all registered resource schemas.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateSchemasCommand extends Command
{
    /** @var string The console command signature. */
    protected $signature = 'api-toolkit:validate-schemas';

    /** @var string The console command description. */
    protected $description = 'Validate all registered resource schemas';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\Services\SchemaValidator  $validator
     * @return int
     */
    public function handle(SchemaValidator $validator): int
    {
        $resourceMap = Config::get('api-toolkit.resources.resource_map', []);

        if (!is_array($resourceMap) || empty($resourceMap)) {
            $this->components->warn('No resources registered in the resource map.');
            return self::SUCCESS;
        }

        try {
            $validator->validate($resourceMap);
        } catch (InvalidSchemaException $exception) {
            $this->components->error('Schema validation failed:');

            foreach ($exception->getErrors() as $error) {
                $this->components->bulletList([(string) $error]);
            }

            return self::FAILURE;
        }

        $this->components->info(
            sprintf('All %d resource schema(s) validated successfully.', count($resourceMap)),
        );

        return self::SUCCESS;
    }
}
