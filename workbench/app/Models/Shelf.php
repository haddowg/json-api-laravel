<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $name
 */
final class Shelf extends Model
{
    protected $table = 'ro_shelves';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Ordered here too, so the relation's declared default is seen to replace it.
     *
     * @return HasMany<Book, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'shelf_id')->orderBy('id', 'desc');
    }

    /**
     * @return BelongsToMany<Book, $this>
     */
    public function favourites(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'ro_shelf_favourite', 'shelf_id', 'book_id');
    }

    /**
     * @return BelongsToMany<Book, $this>
     */
    public function picks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'ro_shelf_pick', 'shelf_id', 'book_id')->withPivot('rank');
    }

    /**
     * The favourites, oldest first, ahead of the books' own default.
     *
     * @return BelongsToMany<Book, $this>
     */
    public function classics(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'ro_shelf_favourite', 'shelf_id', 'book_id')->orderBy('year');
    }
}
