<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Concerns\DerivesTabularSchema;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use SineMacula\Exporter\Contracts\ProvidesTabularExport;
use Tests\Fixtures\Models\User;

/**
 * Fixture resource that derives its tabular schema automatically.
 *
 * Used by the export-negotiator integration suite to prove that the
 * DerivesTabularSchema trait maps scalar, callable-accessor, and
 * callable-compute fields to the correct Column implementations without manual
 * column declarations. The relation field is present to confirm it is skipped
 * by the trait.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ExportableUserResource extends ApiResource implements ProvidesTabularExport
{
    use DerivesTabularSchema;

    /** @var string */
    public const string RESOURCE_TYPE = 'exportable_users';

    /** @var array<int, string> */
    protected static array $default = ['id', 'name', 'email'];

    /**
     * Return the resource schema.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('name'),
            Field::scalar('email'),
            Field::timestamp('created_at'),
            Field::compute('label', static function ($resource): string {
                $user = $resource->resource;

                if (!$user instanceof User) {
                    return '';
                }

                return $user->name . ' <' . $user->email . '>';
            }),
            Relation::to('organization', OrganizationResource::class),
        );
    }
}
