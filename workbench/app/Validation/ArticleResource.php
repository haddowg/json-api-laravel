<?php

declare(strict_types=1);

namespace Workbench\App\Validation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity;

/**
 * The `articles` resource that exercises the always-on validation bridge (PLAN decision
 * 6) on BOTH providers — the Laravel twin of the Symfony bundle's `BaseArticleResource`,
 * shared by the in-memory and Eloquent validation-conformance wirings so identical
 * assertions referee the bridge provider-agnostically.
 *
 * Its constraints, inert on reads, are executed on create/update:
 *  - `title` is required + length-bounded (a missing/short value → `422` at
 *    `/data/attributes/title`);
 *  - `category` carries an enum (`In` → `Rule::in`);
 *  - `publishedAt` carries a closure date bound ("not in the future", resolved against
 *    the frozen clock);
 *  - `expiresAt` carries a cross-field `compareWith('publishedAt', GreaterThan)` evaluated
 *    document-first;
 *  - `couponCode` carries a `when()`-conditional length rule (only a promo code is
 *    length-checked);
 *  - `address` is a nested `Map` whose constrained children surface `422`s at
 *    `/data/attributes/address/<child>` (the one-level cascade);
 *  - `slug` carries a {@see UniqueEntity} realised pre-hydration as `Rule::unique` on the
 *    Eloquent table (inert on the in-memory provider — PLAN decision 6).
 */
#[AsJsonApiResource]
final class ArticleResource extends AbstractResource
{
    public static string $type = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->minLength(3)->maxLength(50),
            Str::make('body')->nullable(),
            Str::make('category')->in(['guide', 'news', 'opinion']),
            // Optional + nullable, with a closure date bound resolved at validation time
            // ("must not be in the future"). Exercises the Before bridge under a frozen
            // clock; inert on reads.
            DateTime::make('publishedAt')->storedAs('published_at')->nullable()
                ->before(static fn(): \DateTimeInterface => \Illuminate\Support\Carbon::now()),
            // Cross-field rule: expiresAt must be after publishedAt — exercises the
            // document-level CompareField execution path.
            DateTime::make('expiresAt')->storedAs('expires_at')->nullable()
                ->compareWith('publishedAt', Comparison::GreaterThan),
            // Conditional constraint via when(): a coupon code is length-checked only when
            // it looks like a promo code, so a short "PROMO-X" fails while a short "FREE"
            // passes — the When bridge declare→execute path end to end.
            Str::make('couponCode')->storedAs('coupon_code')->nullable()->when(
                static fn(mixed $value): bool => \is_string($value) && \str_starts_with($value, 'PROMO-'),
                static function (Str $field): void {
                    $field->minLength(12);
                },
            ),
            // Structured attribute: a nested `address` object whose two children carry
            // their own constraints. The bridge validates them by recursion, so a
            // too-short `street` or a pattern-violating `postcode` surfaces a 422 at
            // /data/attributes/address/<child>. The object round-trips through a single
            // JSON/array member via the Map-level serialize/fill hooks.
            Map::make('address')->nullable()->fields(
                Str::make('street')->required()->minLength(3),
                Str::make('postcode')->required()->pattern('[0-9]{5}'),
            )->serializeUsing(static function (mixed $model): mixed {
                $address = \is_object($model) ? Accessor::get($model, 'address') : null;

                return $address === [] ? null : $address;
            })->fillUsing(static function (mixed $model, mixed $value): mixed {
                $address = null;
                if (\is_array($value)) {
                    $address = [];
                    foreach ($value as $key => $item) {
                        $address[(string) $key] = $item;
                    }
                }

                if (\is_object($model)) {
                    Accessor::set($model, 'address', $address);
                }

                return $model;
            }),
            // Uniqueness (PLAN decision 6): a nullable slug that must be unique across the
            // Eloquent table. Realised as a pre-hydration Rule::unique (self-excluded on
            // update); inert on the in-memory provider.
            Str::make('slug')->nullable()->constrain(new UniqueEntity(['slug'])),
        ];
    }
}
