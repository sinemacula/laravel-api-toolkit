<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Model;
use SineMacula\ApiToolkit\Http\Resources\ApiResource;
use SineMacula\ApiToolkit\Schema\Field;
use SineMacula\ApiToolkit\Schema\Relation;
use Tests\Fixtures\Models\Note;

/**
 * Fixture note resource rendering the key of the model it is attached to.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class NoteSubjectResource extends ApiResource
{
    /** @var string */
    public const string RESOURCE_TYPE = 'note_subjects';

    /** @var array<int, string> */
    protected static array $default = ['id', 'title', 'subject'];

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
            Field::scalar('title'),
            Relation::to('noteable', static function (ApiResource $resource): mixed {

                assert($resource->resource instanceof Note);

                $noteable = $resource->resource->noteable;

                return $noteable instanceof Model ? $noteable->getKey() : null;
            }, 'subject'),
        );
    }
}
