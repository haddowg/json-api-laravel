<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string      $id
 * @property string      $title
 * @property string|null $writer_id
 */
final class Book extends Model
{
    use UuidKeyed;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $table = 'uk_books';

    /**
     * @return BelongsTo<Writer, $this>
     */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(Writer::class, 'writer_id');
    }

    /**
     * @return BelongsToMany<Label, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'uk_book_label', 'book_id', 'label_id');
    }
}
