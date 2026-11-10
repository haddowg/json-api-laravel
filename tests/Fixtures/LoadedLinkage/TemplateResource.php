<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\LoadedLinkage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A template whose `summary` attribute reads its bindings, so on Eloquent both relations
 * are loaded on the model before the relationships render. `bindings` and
 * `sharedBindings` are lazy, `listedBindings` always emits its linkage data.
 */
#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne])]
final class TemplateResource extends AbstractResource
{
    public static string $type = 'templates';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('summary')->readOnly()->extractUsing(static function (mixed $template): ?string {
                if ($template instanceof Template) {
                    return \sprintf('%d bindings, %d shared', $template->bindings->count(), $template->sharedBindings->count());
                }

                return $template instanceof TemplateRecord
                    ? \sprintf('%d bindings, %d shared', \count($template->bindings), \count($template->sharedBindings))
                    : null;
            }),
            HasMany::make('bindings', 'bindings')->readOnly()->countable(),
            HasMany::make('listedBindings', 'bindings')->storedAs('bindings')->readOnly()->withData(),
            BelongsToMany::make('sharedBindings', 'bindings')->readOnly(),
        ];
    }
}
