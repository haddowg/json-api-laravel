<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property int    $id
 * @property string $title
 */
final class Note extends Model
{
    protected $table = 'lr_notes';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return MorphToMany<Board, $this>
     */
    public function pinnedOn(): MorphToMany
    {
        return $this->morphedByMany(Board::class, 'pinnable', 'lr_pinnables');
    }
}
