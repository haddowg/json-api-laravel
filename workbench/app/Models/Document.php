<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The `documents` Eloquent model backing the first-class soft-delete showcase
 * ({@see \Workbench\App\SoftDelete\DocumentResource}). It uses Laravel's {@see SoftDeletes}
 * trait, so `DELETE` sets the `deleted_at` tombstone (a recoverable soft delete) and the
 * default query scope hides trashed rows — the substrate the synthesized restore/force-delete
 * actions and the `withTrashed`/`onlyTrashed` filters build on.
 *
 * @property int                             $id
 * @property string                          $title
 * @property string|null                     $body
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
final class Document extends Model
{
    use SoftDeletes;

    protected $table = 'documents';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
