<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Labels named `secret` are hidden by a global scope.
 *
 * @property mixed  $id
 * @property string $name
 */
class IkLabel extends Model
{
    use IkKeyed;

    public $timestamps = false;

    protected $table = 'ik_labels';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('visible', static fn(Builder $query) => $query->where('ik_labels.name', '<>', 'secret'));
    }

    /**
     * @return BelongsToMany<Model, $this>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(IdKinds::model('book'), 'ik_book_label', 'label_id', 'book_id')->withPivot('weight');
    }
}
