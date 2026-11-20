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
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * Crates, filtered through every kind of relation the caller may or may not read.
 */
#[AsJsonApiResource(readOnly: true, policy: CratePolicy::class)]
final class CrateResource extends AbstractResource
{
    public static string $type = 'crates';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('parcels', 'parcels'),
            // Shares the `parcels` storage member but only admins may read it; a filter path's
            // `parcels` segment names the relation above.
            HasMany::make('sealedParcels', 'parcels')->storedAs('parcels')->security(read: 'readLedger'),
            BelongsTo::make('vault', 'vaults')->nullable(),
            BelongsTo::make('safe', 'safes')->nullable(),
            BelongsTo::make('ledger', 'ledgers')->nullable()->security(read: 'readLedger'),
            // A permission that reads the crate can't answer for every crate at once.
            BelongsTo::make('auditor', 'ledgers')->nullable()->security(read: 'auditCrate'),
            // What each crate is about: a parcel, scoped to its owner, or a safe only admins read.
            MorphTo::make('subject', ['parcels', 'safes'])->identifiedByColumns('subject_type', 'subject_id')->nullable()->hidden(),
            MorphTo::make('stash', ['lockers'])->identifiedByColumns('stash_type', 'stash_id')->nullable()->hidden(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('name'),
            WhereThrough::make('parcelLabel', 'parcels.label'),
            WhereThroughIn::make('parcelLabels', 'parcels.label'),
            WhereThrough::make('parcel', 'parcels'),
            WhereThrough::make('tagName', 'parcels.tags.name'),
            WhereHas::make('hasParcels', 'parcels'),
            WhereThrough::make('vaultCode', 'vault.code'),
            WhereThrough::make('safeCode', 'safe.code'),
            WhereThrough::make('ledgerCode', 'ledger.code'),
            WhereThrough::make('auditorCode', 'auditor.code'),
            WhereAny::make('search', Where::make('name'), WhereThrough::make('label', 'parcels.label')),
            WhereAny::make('anyCode', Where::make('name'), WhereThrough::make('code', 'vault.code')),
            WhereMorph::make('about', 'subject'),
            WhereMorph::make('stash'),
            WhereAny::make('nameOrAbout', Where::make('name'), WhereMorph::make('about', 'subject')),
        ];
    }
}
