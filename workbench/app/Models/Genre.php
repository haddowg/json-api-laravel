<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `genres` Eloquent model — a **natural string-key** id (`trip-hop`), so the
 * fetch-one path and the keyset PK tiebreaker are exercised against a non-numeric
 * primary key.
 *
 * @property string $id
 */
final class Genre extends Model
{
    protected $table = 'genres';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
