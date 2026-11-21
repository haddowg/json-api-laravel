<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Parcels, which each caller sees only of their own, admins all of them.
 */
#[AsJsonApiResource(readOnly: true, policy: OpenPolicy::class)]
final class ParcelResource extends AbstractResource
{
    public static string $type = 'parcels';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('owner_id'),
            Str::make('label'),
            BelongsTo::make('crate', 'crates')->nullable(),
            HasMany::make('tags', 'tags'),
            // Another parcel, on the same table, so the target's scope runs over the table being filtered.
            MorphTo::make('about', ['parcels'])->identifiedByColumns('about_type', 'about_id')->nullable()->hidden(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('owner_id'),
            WhereThrough::make('tagName', 'tags.name'),
            WhereThrough::make('crateVault', 'crate.vault.code'),
            WhereMorph::make('about'),
        ];
    }
}
