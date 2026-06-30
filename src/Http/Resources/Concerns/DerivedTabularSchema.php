<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\Http\Resources\Concerns;

use Illuminate\Http\Request;
use SineMacula\Exporter\Schema\TabularSchema;

/**
 * Concrete TabularSchema implementation backing the DerivesTabularSchema
 * trait.
 *
 * Holds the ordered list of Column instances produced from the resource's
 * compiled field definitions and returns them to the export engine. This
 * class is an implementation detail of the trait and is not intended to be
 * instantiated or extended outside it.
 *
 * @internal
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DerivedTabularSchema extends TabularSchema
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  list<\SineMacula\Exporter\Schema\Column>  $columns
     */
    public function __construct(

        // The request the schema is built for.
        Request $request,

        /** The ordered column definitions for this schema. */
        private readonly array $columns,
    ) {
        parent::__construct($request);
    }

    /**
     * @return list<\SineMacula\Exporter\Schema\Column>
     */
    #[\Override]
    public function columns(): array
    {
        return $this->columns;
    }
}
