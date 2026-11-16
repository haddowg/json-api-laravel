<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $name
 */
final class FpBoard extends Model
{
    protected $table = 'fp_boards';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<FpCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(FpCard::class, 'board_id');
    }

    /**
     * The board's cards, most recently updated first.
     *
     * @return HasMany<FpCard, $this>
     */
    public function newestCards(): HasMany
    {
        return $this->hasMany(FpCard::class, 'board_id')->orderByDesc('updatedAt');
    }

    /**
     * @return BelongsToMany<FpLabel, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(FpLabel::class, 'fp_board_label', 'board_id', 'label_id');
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    public function taggedPairs(): array
    {
        return FirstPageSeed::taggedPairs((int) $this->id);
    }
}
