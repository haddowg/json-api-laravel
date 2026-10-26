<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PersisterExtension;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $body
 * @property string      $status
 * @property string|null $archived_at
 */
final class Note extends Model
{
    public $timestamps = false;

    protected $table = 'pe_notes';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
