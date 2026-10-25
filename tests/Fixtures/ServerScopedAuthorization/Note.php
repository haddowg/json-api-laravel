<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedAuthorization;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $body
 */
final class Note extends Model
{
    protected $table = 'ssa_notes';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
