<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `genres` Eloquent model (storage table `mc_genres`) — a client-supplied natural-key
 * (string) primary key, so incrementing is off and the key type is string.
 *
 * @property string $id
 * @property string $name
 */
final class Genre extends Model
{
    protected $table = 'mc_genres';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
