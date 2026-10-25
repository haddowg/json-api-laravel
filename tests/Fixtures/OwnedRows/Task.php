<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OwnedRows;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $title
 * @property int    $owner_id
 * @property int    $project_id
 */
#[ScopedBy(OwnedByCurrentUser::class)]
final class Task extends Model
{
    protected $table = 'or_tasks';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
