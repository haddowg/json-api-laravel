<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int    $id
 * @property string $name
 */
final class ReadingList extends Model
{
    protected $table = 'ff_reading_lists';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsToMany<Book, $this>
     */
    public function rankedBooks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'ff_reading_list_book', 'reading_list_id', 'book_id')
            ->withPivot('rank');
    }
}
