<?php

declare(strict_types = 1);

require __DIR__ . '/vendor/autoload.php';

use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Http\Resources\Schema\Field;
use SineMacula\ApiToolkit\Http\Resources\Schema\Relation;

// --- Minimal child resources so class-strings resolve cleanly ----------------

final class OrgResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'organization';
    protected static array $default   = ['id', 'name'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('name'),
        );
    }
}

final class StepResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'step';
    protected static array $default   = ['id', 'name', 'task'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Field::scalar('name'),
            Relation::to('task', 'name'), // accessor-only relation
        );
    }
}

final class PathResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'path';
    protected static array $default   = ['id', 'from', 'to'];

    public static function schema(): array
    {
        return Field::set(
            Field::scalar('id'),
            Relation::to('from', StepResource::class),
            Relation::to('to', StepResource::class),
        );
    }
}

// --- Complex parent resource to exercise everything --------------------------

final class WorkflowResource extends ApiResource
{
    public const string RESOURCE_TYPE = 'workflow';
    protected static array $default   = ['name', 'status_label', 'organization', 'steps', 'paths'];

    public static function schema(): array
    {
        return Field::set(
            // Scalars
            Field::scalar('id'),
            Field::scalar('name'),
            Field::scalar('description'),

            // Accessor (string path)
            Field::accessor('status_label', 'status.name')
                ->guard(fn (ApiResource $r) => isset($r->resource->status)),

            // Accessor (callable) – format date
            Field::accessor('created_at_iso', fn (ApiResource $r) => $r->resource->created_at?->toIso8601String()),

            // Scalar + transformer (enum/value extraction)
            Field::scalar('visibility')
                ->transform(fn (ApiResource $r, mixed $v) => $v?->value ?? $v),

            // Relation wrapped by resource
            Relation::to('organization', OrgResource::class),

            // Relation with accessor only + alias (eager-loads relation, returns property)
            Relation::to('owner', 'name', 'owner_name'),

            // Relation with extras, plus a transformer that could propagate context/scope
            Relation::to('steps', StepResource::class)
                ->extras('steps.task', 'steps.fromPaths')
                ->transform(function (ApiResource $parent, mixed $wrapped) {
                    // Example: if you had (new StepResource($step))->setScope(...), you could
                    // do that here by mapping over the collection or touching the child.
                    return $wrapped;
                }),

            // Another nested relation
            Relation::to('paths', PathResource::class),

            // Relation with accessor path from related model (no wrapping)
            Relation::to('organization', 'country.code', 'org_country_code'),
        );
    }
}

// --- Inspect the schema itself -----------------------------------------------

$schema = WorkflowResource::schema();

echo "=== SCHEMA ===\n";
print_r($schema);

// --- Show which relations would be eager-loaded for different field sets -----

$requestedA = [
    'name',
    'status_label',
    'owner_name'
];                      // mix of scalar/accessor + aliased relation accessor
$requestedB = [
    'organization',
    'org_country_code',
    'steps',
    'paths'
];      // wrapped relations + accessor-from-relation
$requestedAll = array_keys(WorkflowResource::schema());                     // simulate :all

echo "\n=== relationsFor (A) ===\n";
print_r(WorkflowResource::relationsFor($requestedA));   // expect: ['owner']

echo "\n=== relationsFor (B) ===\n";
print_r(WorkflowResource::relationsFor($requestedB));   // expect: ['organization','steps','steps.task','steps.fromPaths','paths']

echo "\n=== relationsFor (:all) ===\n";
print_r(WorkflowResource::relationsFor($requestedAll)); // superset incl. owner/org/steps paths
