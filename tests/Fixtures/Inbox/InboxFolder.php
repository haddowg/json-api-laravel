<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $name
 */

final class InboxFolder extends Model
{
    protected $table = 'inb_folders';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<InboxItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InboxItem::class, 'folder_id');
    }
}
