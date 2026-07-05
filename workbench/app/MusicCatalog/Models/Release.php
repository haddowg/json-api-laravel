<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `releases` Eloquent model (storage table `mc_releases`) — the composite-attribute
 * showcase: each composite attribute (`format` = OneOf, `packaging` = Obj,
 * `availability`/`dimensions` = ArrayHash+Shape) is a single `json` column with an
 * `array` cast, the storage decision for composite types.
 *
 * @property int                       $id
 * @property int|null                  $album_id
 * @property string                    $catalog_number
 * @property array<string, mixed>|null $format
 * @property array<string, mixed>|null $packaging
 * @property array<string, mixed>|null $availability
 * @property array<string, mixed>|null $dimensions
 */
final class Release extends Model
{
    protected $table = 'mc_releases';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'format' => 'array',
        'packaging' => 'array',
        'availability' => 'array',
        'dimensions' => 'array',
    ];

    /**
     * @return BelongsTo<Album, $this>
     */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class, 'album_id');
    }
}
