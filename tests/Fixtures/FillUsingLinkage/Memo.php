<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use Illuminate\Database\Eloquent\Model;

/**
 * A memo stores the ids of its setting, settings, label and labels in plain columns. None is an
 * Eloquent relation, so only the resource's fill hooks write them.
 *
 * @property int               $id
 * @property string            $title
 * @property string|null       $setting_id
 * @property list<string>|null $setting_ids
 * @property string|null       $label_id
 * @property list<string>|null $label_ids
 */
final class Memo extends Model
{
    public $timestamps = false;

    protected $table = 'fl_memos';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $casts = ['setting_ids' => 'array', 'label_ids' => 'array'];
}
