<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * `fk-grants`, served by {@see FkGrantProvider}, whose write-only `secrets` reach a type a
 * global scope narrows under no foreign key. It takes no update, so only a create or an
 * action's input sets them. `received` shows the ids the last write sent.
 */
#[AsJsonApiResource(operations: [Operation::FetchOne, Operation::Create])]
final class FkGrantResource extends AbstractResource
{
    public static string $type = 'fk-grants';

    public function fields(): array
    {
        return [
            Id::make(),
            ArrayList::make('received')->readOnly(),
            HasMany::make('secrets', 'fk-secrets')->writeOnly(),
        ];
    }
}
