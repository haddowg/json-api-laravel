<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A minimal `belongsToMany` parent for {@see \haddowg\JsonApiLaravel\Tests\Feature\EloquentBelongsToManyRelatedIdTest}:
 * its pivot table carries its own `id` column, the collision that would clobber the related
 * key under an unqualified `select *`.
 *
 * @property int $id
 */
final class Widget extends Model
{
    protected $table = 'bt_widgets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsToMany<Gadget, $this>
     */
    public function gadgets(): BelongsToMany
    {
        return $this->belongsToMany(Gadget::class, 'bt_widget_gadget', 'widget_id', 'gadget_id')
            ->withPivot('position');
    }
}
