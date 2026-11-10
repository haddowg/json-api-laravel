<?php

declare(strict_types=1);

namespace Workbench\App\Union;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Union;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * The union witness, shared by both providers. `value` is the nullable union of a bounded
 * string, a number, a boolean and a bounded list of a nested union. `label` is required,
 * `mode` has an enum string member, so it projects as a `oneOf`, and is not nullable.
 * `due` and `at` have a top-level date and date-time member, and `extra` holds a union
 * inside an object.
 */
#[AsJsonApiResource]
final class PresetResource extends AbstractResource
{
    public static string $type = 'presets';

    public function fields(): array
    {
        return [
            Id::make(),
            Union::make('value')->of(
                Str::make()->maxLength(20),
                Decimal::make(),
                Boolean::make(),
                ArrayList::make()->maxItems(3)->of(Union::of(Str::make()->maxLength(10), Decimal::make(), Boolean::make())),
            )->nullable(),
            Union::make('label')->of(Str::make()->maxLength(20), Integer::make(), Boolean::make(), ArrayList::make())->required(),
            Union::make('mode')->of(Str::make()->in(['auto', 'off']), Integer::make()->min(0)),
            Union::make('due')->of(Date::make(), Boolean::make())->nullable(),
            Union::make('at')->of(DateTime::make(), Boolean::make())->nullable(),
            Obj::make('extra')->nullable()->fields(
                Union::make('limit')->of(Date::make(), Integer::make()->max(100)),
            ),
        ];
    }
}
