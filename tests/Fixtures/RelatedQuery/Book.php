<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Person|null $writer
 */
final class Book extends Model
{
    protected $table = 'rq_books';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'writer_id');
    }
}
