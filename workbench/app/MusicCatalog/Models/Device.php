<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `devices` Eloquent model (storage table `mc_devices`) — an app-generated ULID string
 * primary key, so incrementing is off and the key type is string (the id is minted by the
 * resource's `Id::make()->ulid()->generated()` before the persister assigns it).
 *
 * @property string $id
 * @property string $label
 */
final class Device extends Model
{
    protected $table = 'mc_devices';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
