<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * A writable view of the crates whose pivot relation renders its linkage on every response.
 * `keptRecords` reads the same join, rendered whatever `fields[npBins]` lists.
 */
#[AsJsonApiResource(operations: [Operation::FetchOne, Operation::Create, Operation::Update])]
final class NpBinResource extends AbstractResource
{
    public static string $type = 'npBins';

    public function fields(): array
    {
        return [
            NpKeys::id('c'),
            Str::make('title'),
            BelongsToMany::make('records', 'npRecords')
                ->withData()
                ->fields(
                    Integer::make('position'),
                    Str::make('label')->computedUsing(static function (mixed $edge): string {
                        if (!$edge instanceof PivotEdge) {
                            return '';
                        }
                        $bin = Accessor::get($edge->parent, 'title');
                        $record = Accessor::get($edge->related, 'title');

                        return (\is_string($bin) ? $bin : '') . ' / ' . (\is_string($record) ? $record : '');
                    }),
                ),
            BelongsToMany::make('keptRecords', 'npRecords')
                ->storedAs('records')
                ->notSparseField()
                ->readOnly()
                ->fields(Integer::make('position')),
        ];
    }
}
