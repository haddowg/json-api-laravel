<?php

declare(strict_types=1);

namespace Workbench\App\Validation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Validation\Constraint\LaravelRules;

/**
 * A third type over the composite witness's backing, carrying lists of structured
 * elements: `steps` holds discriminated {@see OneOf} elements and `checks` plain
 * {@see Obj} elements, each list one `json` value. A check nests a list of its own
 * and carries a `checkedAt` date-time, which an `array` cast stores as the date
 * object's JSON dump.
 */
#[AsJsonApiResource]
final class CompositePipelineResource extends AbstractResource
{
    public static string $type = 'pipelines';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name')->required(),
            ArrayList::make('steps')->nullable()->maxItems(3)->of(
                OneOf::make('step')->discriminator('operation')
                    ->variant('trim')
                    ->variant('find_replace', Obj::make('params')->required()->fields(
                        Str::make('find')->required()->maxLength(20),
                        Str::make('replace')->required(),
                    )),
            ),
            ArrayList::make('checks')->nullable()->of(
                Obj::make('check')->fields(
                    Str::make('label')->required()->maxLength(20),
                    Str::make('code')->nullable()->constrain(LaravelRules::make(['alpha_dash'])),
                    ArrayList::make('rules')->nullable()->of(Obj::make('rule')->fields(Str::make('pattern')->required())),
                    DateTime::make('checkedAt')->nullable(),
                ),
            ),
        ];
    }
}
