<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Card extends Model
{
    public $timestamps = false;

    protected $table = 'fu_cards';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
