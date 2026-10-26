<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UuidKeys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 */
final class Writer extends Model
{
    use UuidKeyed;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $table = 'uk_writers';

    /**
     * @return HasMany<Book, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'writer_id');
    }
}
