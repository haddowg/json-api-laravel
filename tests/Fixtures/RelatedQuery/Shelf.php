<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property \Illuminate\Database\Eloquent\Collection<int, Book> $books
 * @property Book|null $featured
 */
final class Shelf extends Model
{
    protected $table = 'rq_shelves';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<Book, $this>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'rq_shelf_book', 'shelf_id', 'book_id');
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function featured(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'featured_id');
    }
}
