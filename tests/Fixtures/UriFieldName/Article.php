<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UriFieldName;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Article extends Model
{
    protected $table = 'ufn_articles';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<Comment, $this>
     */
    public function remarks(): HasMany
    {
        return $this->hasMany(Comment::class, 'article_id');
    }

    /**
     * @return BelongsTo<Author, $this>
     */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }
}
