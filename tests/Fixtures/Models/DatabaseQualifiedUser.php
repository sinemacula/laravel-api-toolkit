<?php

declare(strict_types = 1);

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model naming the users table through its connection's database.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class DatabaseQualifiedUser extends Model
{
    /**
     * Return the users table qualified by the connection's database.
     *
     * @return string
     */
    #[\Override]
    public function getTable(): string
    {
        return $this->getConnection()->getDatabaseName() . '.users';
    }
}
