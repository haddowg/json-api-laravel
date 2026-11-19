<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionInputContext;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * A `code` only an update may write, and a `note` only an update may clear.
 *
 * @internal
 */
#[AsJsonApiResource(readOnly: true)]
final class ContextNoteResource extends AbstractResource
{
    public static string $type = 'contextNotes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('code')->readOnlyOnCreate(),
            Str::make('note')->onUpdate(static function (StrBuilder $field): void {
                $field->nullable();
            }),
        ];
    }
}
