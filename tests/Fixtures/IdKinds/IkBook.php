<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property mixed  $id
 * @property string $title
 */
class IkBook extends Model
{
    use IkKeyed;

    public $timestamps = false;

    protected $table = 'ik_books';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Model, $this>
     */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(IdKinds::model('writer'), 'writer_id');
    }

    /**
     * @return BelongsToMany<Model, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(IdKinds::model('label'), 'ik_book_label', 'book_id', 'label_id')->withPivot('weight');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    /**
     * @return HasMany<IkPin, $this>
     */
    public function pins(): HasMany
    {
        return $this->hasMany(IkPin::class, 'book_id');
    }

    /**
     * @return list<string>
     */
    protected function keyColumns(): array
    {
        return ['writer_id', 'featured_by', 'writer_ref'];
    }
}
