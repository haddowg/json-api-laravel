<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FilterFamilies;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property int    $shelf_id
 * @property string $title
 * @property string $genre
 * @property int    $pages
 */
final class Book extends Model
{
    protected $table = 'ff_books';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
