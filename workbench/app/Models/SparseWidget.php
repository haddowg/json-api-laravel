<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The `sparseWidgets` Eloquent model backing the sparse-by-default conformance suite:
 * a cheap `name` column plus an `expensive_score` column the
 * {@see \Workbench\App\Sparse\SparseWidgetResource} exposes as the sparse-by-default
 * `expensiveScore` attribute — so the same assertions witness the opt-in visibility
 * tier over a real column, not just an in-memory array.
 *
 * @property int    $id
 * @property string $name
 * @property int    $expensive_score
 */
final class SparseWidget extends Model
{
    protected $table = 'sparse_widgets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expensive_score' => 'integer',
    ];
}
