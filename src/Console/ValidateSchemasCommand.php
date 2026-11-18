<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Console;

use Illuminate\Console\Command;
use SineMacula\ApiToolkit\Exceptions\InvalidSchemaException;
use SineMacula\ApiToolkit\Http\Resources\ResourceTypeIndex;
use SineMacula\ApiToolkit\Schema\Introspection\CatalogueProbe;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport;
use SineMacula\ApiToolkit\Schema\Validation\SchemaValidator;
use SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration;
use SineMacula\ApiToolkit\Support\ResourceMap;

/**
 * Artisan command to validate all registered resource schemas.
 *
 * Fails on a declaration it could not verify, since it gates deployment.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ValidateSchemasCommand extends Command
{
    /** @var string The console command signature */
    protected $signature = <<<'EOD'
        api-toolkit:validate-schemas
                {--allow-empty : Exit successfully when no resources are registered}
                {--allow-unverified : Exit successfully when a declaration could not be verified against the database}
        EOD;

    /** @var string The console command description */
    protected $description = 'Validate all registered resource schemas';

    /**
     * Execute the console command.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Validation\SchemaValidator  $validator
     * @return int
     */
    public function handle(SchemaValidator $validator): int
    {
        $resourceMap = ResourceMap::configured();

        if ($resourceMap === [] && ResourceTypeIndex::all() === []) {
            return $this->reportEmptyMap();
        }

        $report = $this->validate($validator, $resourceMap);

        $this->reportErrors($report);
        $this->reportUnverified($report);

        return $report->errors === [] ? $this->conclude($report) : self::FAILURE;
    }

    /**
     * Validate the resource map, reporting a raw schema the compiler refuses as
     * its defects.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Validation\SchemaValidator  $validator
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>, class-string>  $resourceMap
     * @return \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport
     */
    private function validate(SchemaValidator $validator, array $resourceMap): SchemaValidationReport
    {
        try {
            return $validator->report($resourceMap);
        } catch (InvalidSchemaException $exception) {
            return new SchemaValidationReport($exception->getErrors(), [], count($resourceMap));
        }
    }

    /**
     * Conclude a run that found no defect, failing on anything unverified
     * unless that is allowed.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport  $report
     * @return int
     */
    private function conclude(SchemaValidationReport $report): int
    {
        if ($report->unverified === []) {

            $this->components->info(sprintf('All %d resource schema(s) validated successfully.', $report->resourceCount));

            return self::SUCCESS;
        }

        if ($this->option('allow-unverified') !== true) {
            return self::FAILURE;
        }

        $this->components->warn(sprintf(
            '%d resource schema(s) validated; %d declaration(s) were not verified.',
            $report->resourceCount,
            count($report->unverified),
        ));

        return self::SUCCESS;
    }

    /**
     * Report a resource map with nothing in it, failing unless that is allowed.
     *
     * @return int
     */
    private function reportEmptyMap(): int
    {
        if ($this->option('allow-empty') === true) {

            $this->components->warn('No resources registered in the resource map.');

            return self::SUCCESS;
        }

        $this->components->error('No resources are registered in the resource map, so nothing was validated. Register or discover a resource, or pass --allow-empty.');

        return self::FAILURE;
    }

    /**
     * Report every schema defect found.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport  $report
     * @return void
     */
    private function reportErrors(SchemaValidationReport $report): void
    {
        if ($report->errors === []) {
            return;
        }

        $this->components->error('Schema validation failed:');

        $this->components->bulletList(array_map(strval(...), $report->errors));
    }

    /**
     * Report every unverified declaration, grouped under why its model's
     * catalogue could not be read, then how to clear them.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Validation\SchemaValidationReport  $report
     * @return void
     */
    private function reportUnverified(SchemaValidationReport $report): void
    {
        if ($report->unverified === []) {
            return;
        }

        $this->components->error(sprintf('%d declaration(s) could not be verified against the database:', count($report->unverified)));

        $probe = new CatalogueProbe;

        foreach ($this->groupByModel($report->unverified) as $modelClass => $declarations) {

            $this->line(sprintf('  %s: %s', $modelClass, $probe->describe(new $modelClass)));
            $this->components->bulletList(array_map($this->describeDeclaration(...), $declarations));
        }

        if ($this->option('allow-unverified') === true) {
            return;
        }

        $this->components->error('Run against a migrated database the application can reach, or pass --allow-unverified.');
    }

    /**
     * Group the unverified declarations by the model they were read against.
     *
     * @param  array<int, \SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>  $unverified
     * @return array<class-string<\Illuminate\Database\Eloquent\Model>, array<int, \SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration>>
     */
    private function groupByModel(array $unverified): array
    {
        $groups = [];

        foreach ($unverified as $declaration) {
            $groups[$declaration->modelClass][] = $declaration;
        }

        return $groups;
    }

    /**
     * Describe the declaration, naming its own cause when it carries one.
     *
     * @param  \SineMacula\ApiToolkit\Schema\Validation\UnverifiedDeclaration  $declaration
     * @return string
     */
    private function describeDeclaration(UnverifiedDeclaration $declaration): string
    {
        return $declaration->cause === null
            ? (string) $declaration
            : sprintf('%s: %s', $declaration, $declaration->cause);
    }
}
