<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $title
 */
final class Book extends Model
{
    protected $table = 'idm_books';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
