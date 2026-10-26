<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $name
 */
final class Label extends Model
{
    use UuidKeyed;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $table = 'uk_labels';

    /**
     * @return BelongsToMany<Book, $this>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'uk_book_label', 'label_id', 'book_id');
    }
}
