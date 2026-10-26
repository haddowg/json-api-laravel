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

    /**
     * A task created through the API belongs to user 1 in project 1.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['owner_id' => 1, 'project_id' => 1];
}
