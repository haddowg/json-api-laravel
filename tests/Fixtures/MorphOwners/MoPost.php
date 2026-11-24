<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use Illuminate\Database\Eloquent\Model;

/**
 * Declares no relation to its notes: they are read through the notes' polymorphic owner.
 *
 * @property int    $id
 * @property string $title
 */
final class MoPost extends Model
{
    public $timestamps = false;

    protected $table = 'mo_posts';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
