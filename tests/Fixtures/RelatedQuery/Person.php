<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedQuery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Person|null $mentor
 * @property \Illuminate\Database\Eloquent\Collection<int, Book> $books
 */
final class Person extends Model
{
    protected $table = 'rq_people';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<Person, $this>
     */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'mentor_id');
    }

    /**
     * @return HasMany<Book, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'writer_id');
    }
}
