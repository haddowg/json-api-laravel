<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\Validation\SubmissionKind;

/**
 * The `submissions` Eloquent model backing the JSON type conformance suite: each object
 * attribute and list is a `json` column with an `array` cast.
 *
 * @property int                       $id
 * @property string                    $name
 * @property int|null                  $count
 * @property float|null                $ratio
 * @property bool|null                 $flag
 * @property SubmissionKind|null       $kind
 * @property list<mixed>|null          $tags
 * @property array<string, mixed>|null $options
 * @property list<mixed>|null          $rows
 * @property array<string, mixed>|null $mapping
 * @property array<string, mixed>|null $block
 * @property array<string, mixed>|null $review
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
        'kind' => SubmissionKind::class,
        'tags' => 'array',
        'options' => 'array',
        'rows' => 'array',
        'mapping' => 'array',
        'block' => 'array',
        'review' => 'array',
    ];
}
