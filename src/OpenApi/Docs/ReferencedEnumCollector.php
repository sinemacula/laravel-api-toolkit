<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Docs;

use SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents;
use SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration;
use SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry;

/**
 * Collects every enum class the API references across all audiences.
 *
 * The enum registry is populated only as a side effect of assembling a
 * document, and its set is reset per audience, so no single assembly names
 * every enum. To enumerate the full set this exports each configured audience
 * in turn, reading the registry back after each and unioning the results keyed
 * by class. The outcome is exactly the enums the API actually references,
 * deduplicated across audiences, which is the correct scope for the reference
 * documentation.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ReferencedEnumCollector
{
    /**
     * Create a new referenced enum collector.
     *
     * @param  \SineMacula\ApiToolkit\OpenApi\ExportOpenApiComponents  $exporter
     * @param  \SineMacula\ApiToolkit\OpenApi\Schema\EnumSchemaRegistry  $registry
     * @param  \SineMacula\ApiToolkit\OpenApi\Resolution\AudienceConfiguration  $audiences
     */
    public function __construct(

        /** Assembles a document, populating the enum registry as a side effect. */
        private ExportOpenApiComponents $exporter,

        /** Holds the enum classes the last assembled document referenced. */
        private EnumSchemaRegistry $registry,

        /** Resolves the configured audiences to assemble across. */
        private AudienceConfiguration $audiences,
    ) {}

    /**
     * Collect the distinct enum classes referenced across every audience.
     *
     * @return list<class-string>
     */
    public function collect(): array
    {
        $names = $this->audiences->names();
        $names = $names === [] ? [$this->audiences->defaultAudience()] : $names;

        $enums = [];

        foreach ($names as $audience) {

            $this->exporter->export($audience);

            foreach ($this->registry->classes() as $class) {
                $enums[$class] = true;
            }
        }

        return array_keys($enums);
    }
}
