<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `submissions` Eloquent model backing the JSON type conformance suite: each object
 * attribute and list is a `json` column with an `array` cast.
 *
 * @property int                       $id
 * @property string                    $name
 * @property int|null                  $count
 * @property float|null                $ratio
 * @property bool|null                 $flag
 * @property list<mixed>|null          $tags
 * @property array<string, mixed>|null $options
 * @property list<mixed>|null          $rows
 * @property array<string, mixed>|null $mapping
 * @property array<string, mixed>|null $block
 */
final class Submission extends Model
{
    protected $table = 'submissions';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'count' => 'integer',
        'ratio' => 'float',
        'flag' => 'boolean',
        'day' => 'date',
        'at' => 'datetime',
        'tags' => 'array',
        'options' => 'array',
        'rows' => 'array',
        'mapping' => 'array',
        'block' => 'array',
    ];
}
