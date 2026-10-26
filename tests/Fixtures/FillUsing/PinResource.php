<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;

final class PinResource extends AbstractResource
{
    public static string $type = 'pins';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('label'),
            MorphTo::make('target', ['boards', 'cards'])
                ->nullable()
                ->extractUsing(static function (mixed $pin): ?object {
                    \assert($pin instanceof Pin);

                    return match ($pin->target_kind) {
                        'board' => Board::query()->find($pin->target_key),
                        'card' => Card::query()->find($pin->target_key),
                        default => null,
                    };
                })
                ->fillUsing(static function (mixed $pin, mixed $linkage): Pin {
                    \assert($pin instanceof Pin && $linkage instanceof ToOneRelationship);
                    $target = $linkage->resourceIdentifier;
                    $pin->target_kind = $target === null ? null : \rtrim($target->type, 's');
                    $pin->target_key = $target?->id === null ? null : (int) $target->id;

                    return $pin;
                }),
            HasMany::make('tags', 'tags')
                ->extractUsing(static function (mixed $pin): array {
                    \assert($pin instanceof Pin);

                    return Tag::query()->whereKey($pin->tag_list ?? [])->orderBy('id')->get()->all();
                })
                ->fillUsing(static function (mixed $pin, mixed $linkage, array $data): Pin {
                    \assert($pin instanceof Pin && $linkage instanceof ToManyRelationship);
                    $incoming = \array_map('intval', \array_filter($linkage->getResourceIdentifierIds(), static fn(?string $id): bool => $id !== null));
                    $current = $pin->tag_list ?? [];
                    $list = match ($data['mode'] ?? Mode::Replace) {
                        Mode::Add => \array_values(\array_unique([...$current, ...$incoming])),
                        Mode::Remove => \array_values(\array_diff($current, $incoming)),
                        default => $incoming,
                    };
                    \sort($list);
                    $pin->tag_list = $list;

                    return $pin;
                }),
        ];
    }
}
