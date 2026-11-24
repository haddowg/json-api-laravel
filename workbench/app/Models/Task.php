<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int      $id
 * @property string   $title
 * @property int|null $owner_id
 */
final class Task extends Model
{
    protected $table = 'rr_tasks';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Member, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<Member, $this>
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'rr_task_watcher', 'task_id', 'member_id');
    }
}
