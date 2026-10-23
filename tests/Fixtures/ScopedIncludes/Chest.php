<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property int    $id
 * @property string $label
 */
final class Chest extends Model
{
    protected $table = 'si_chests';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return MorphToMany<Coin, $this>
     */
    public function coins(): MorphToMany
    {
        return $this->morphedByMany(Coin::class, 'item', 'si_chest_items', 'chest_id', 'item_id');
    }

    /**
     * @return MorphToMany<Mark, $this>
     */
    public function marks(): MorphToMany
    {
        return $this->morphedByMany(Mark::class, 'item', 'si_chest_items', 'chest_id', 'item_id');
    }

    /**
     * @return list<Coin|Mark>
     */
    public function holdings(): array
    {
        return [...\array_values($this->coins()->get()->all()), ...\array_values($this->marks()->get()->all())];
    }
}
