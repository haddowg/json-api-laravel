<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link row of a book's pins, holding each pinned resource's wire id.
 *
 * @property int    $id
 * @property string $pin_type
 * @property string $pin_id
 */
final class IkPin extends Model
{
    use IkKeyed;

    public $timestamps = false;

    protected $table = 'ik_pins';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    public function initializeIkKeyed(): void
    {
        if (IdKinds::kind() === 'object') {
            $this->mergeCasts(['book_id' => IkKeyCast::class]);
        }
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(IdKinds::model('book'), 'book_id');
    }
}
