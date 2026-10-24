<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Flatten;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int      $id
 * @property string   $title
 * @property int|null $author_id
 * @property int|null $editor_id
 */
final class Book extends Model
{
    protected $table = 'fl_books';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Author, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    /**
     * @return BelongsTo<Author, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'editor_id');
    }
}
