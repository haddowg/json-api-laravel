<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * A type whose only relation is written by a fill hook, to labels the reference provider
 * serves, so that hook is the only reason a create can name a missing resource.
 */
final class StickyResource extends AbstractResource
{
    public static string $type = 'stickies';

    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsTo::make('label', 'labels')
                ->withoutRelatedEndpoint()
                ->extractUsing(function (mixed $sticky): ?object {
                    \assert($sticky instanceof Sticky || $sticky instanceof MemoRecord);
                    $id = $sticky->label_id;

                    return \is_scalar($id) ? $this->providers->forType('labels')->fetchOne('labels', (string) $id) : null;
                })
                ->fillUsing(static function (mixed $sticky, mixed $linkage): Sticky|MemoRecord {
                    \assert(($sticky instanceof Sticky || $sticky instanceof MemoRecord) && $linkage instanceof ToOneRelationship);
                    $sticky->label_id = $linkage->resourceIdentifier?->id;

                    return $sticky;
                }),
        ];
    }
}
