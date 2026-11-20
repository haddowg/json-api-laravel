<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `panels` Eloquent model backing the field-selection conformance suite, the same
 * shape as the in-memory {@see \Workbench\App\FieldSelection\Panel}: each object
 * attribute is one `json` column with an `array` cast.
 *
 * @property int                       $id
 * @property string                    $name
 * @property array<string, mixed>|null $values
 * @property array<string, mixed>|null $groups
 * @property array<string, mixed>|null $settings
 * @property array<string, mixed>|null $address
 * @property list<mixed>|null          $rows
 * @property list<mixed>|null          $archive
 * @property list<string>|null         $tags
 * @property list<mixed>|null          $blocks
 * @property int|null                  $curator_id
 */
final class Panel extends Model
{
    protected $table = 'fs_panels';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'values' => 'array',
        'groups' => 'array',
        'settings' => 'array',
        'address' => 'array',
        'rows' => 'array',
        'archive' => 'array',
        'tags' => 'array',
        'blocks' => 'array',
    ];

    /**
     * @return BelongsTo<Curator, $this>
     */
    public function curator(): BelongsTo
    {
        return $this->belongsTo(Curator::class, 'curator_id');
    }
}
