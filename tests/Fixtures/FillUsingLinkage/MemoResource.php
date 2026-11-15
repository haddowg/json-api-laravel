<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToBuilder;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasManyBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;

/**
 * Every relation is written by its own fill hook into a column of ids: `setting` and
 * `settings` name template settings a custom provider serves, `label` and `labels` labels the
 * reference provider serves.
 */
final class MemoResource extends AbstractResource
{
    public static string $type = 'memos';

    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            $this->toOne('setting', 'template-settings', 'setting_id'),
            $this->toMany('settings', 'template-settings', 'setting_ids'),
            $this->toOne('label', 'labels', 'label_id'),
            $this->toMany('labels', 'labels', 'label_ids'),
        ];
    }

    private function toOne(string $name, string $type, string $column): BelongsToBuilder
    {
        return BelongsTo::make($name, $type)
            ->withoutRelatedEndpoint()
            ->extractUsing(function (mixed $memo) use ($type, $column): ?object {
                \assert(\is_object($memo));
                $id = $memo->{$column};

                return \is_scalar($id) ? $this->providers->forType($type)->fetchOne($type, (string) $id) : null;
            })
            ->fillUsing(static function (mixed $memo, mixed $linkage) use ($column): object {
                \assert(\is_object($memo) && $linkage instanceof ToOneRelationship);
                $memo->{$column} = $linkage->resourceIdentifier?->id;

                return $memo;
            });
    }

    private function toMany(string $name, string $type, string $column): HasManyBuilder
    {
        return HasMany::make($name, $type)
            ->withoutRelatedEndpoint()
            ->extractUsing(function (mixed $memo) use ($type, $column): array {
                \assert(\is_object($memo));
                $ids = $memo->{$column};

                return \array_values(\array_filter(\array_map(
                    fn(mixed $id): ?object => \is_scalar($id) ? $this->providers->forType($type)->fetchOne($type, (string) $id) : null,
                    \is_array($ids) ? $ids : [],
                )));
            })
            ->fillUsing(static function (mixed $memo, mixed $linkage, array $data) use ($column): object {
                \assert(\is_object($memo) && $linkage instanceof ToManyRelationship);
                $incoming = \array_values(\array_filter($linkage->getResourceIdentifierIds(), static fn(?string $id): bool => $id !== null));
                $current = \array_values(\array_filter(\is_array($memo->{$column}) ? $memo->{$column} : [], '\is_string'));
                $memo->{$column} = match ($data['mode'] ?? Mode::Replace) {
                    Mode::Add => \array_values(\array_unique([...$current, ...$incoming])),
                    Mode::Remove => \array_values(\array_diff($current, $incoming)),
                    default => $incoming,
                };

                return $memo;
            });
    }
}
