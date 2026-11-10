<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property int    $id
 * @property string $name
 */
final class Board extends Model
{
    protected $table = 'lr_boards';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<BoardItem, $this>
     */
    public function boardItems(): HasMany
    {
        return $this->hasMany(BoardItem::class, 'board_id')->orderBy('id');
    }

    /**
     * @return MorphToMany<Note, $this>
     */
    public function pins(): MorphToMany
    {
        return $this->morphToMany(Note::class, 'pinnable', 'lr_pinnables')->withPivot('rank');
    }
}
