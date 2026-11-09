<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int      $id
 * @property string   $label
 * @property int|null $curator_id
 */
final class Shelf extends Model
{
    protected $table = 'idm_shelves';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Curator, $this>
     */
    public function curator(): BelongsTo
    {
        return $this->belongsTo(Curator::class);
    }

    /**
     * @return BelongsToMany<Book, $this>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'idm_shelf_book', 'shelf_id', 'book_id')->withPivot('position');
    }
}
