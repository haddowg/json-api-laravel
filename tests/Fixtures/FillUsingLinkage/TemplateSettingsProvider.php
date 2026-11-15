<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;

/**
 * Serves `template-settings` from a fixed list, with no batched read, so an id it doesn't hold
 * is only ever found missing through `fetchOne()`.
 *
 * @extends AbstractDataProvider<object>
 */
final class TemplateSettingsProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === TemplateSettingResource::$type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return self::all()[$id] ?? null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider($type, self::all()))->fetchCollection($type, $criteria);
    }

    /**
     * @return array<int|string, TemplateSetting>
     */
    private static function all(): array
    {
        return ['1' => new TemplateSetting('1', 'Compact'), '2' => new TemplateSetting('2', 'Wide')];
    }
}
