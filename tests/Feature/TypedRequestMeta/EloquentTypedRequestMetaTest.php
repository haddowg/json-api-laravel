<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\TypedRequestMeta;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class EloquentTypedRequestMetaTest extends TypedRequestMetaTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    #[Test]
    public function anOpenPivotHandsItsUndeclaredMembersToItsEntry(): void
    {
        $this->sendJsonApiRequest('POST', '/api/tr-shelves/1/relationships/items', [
            'data' => [['type' => 'tr-items', 'id' => '2', 'meta' => ['pivot' => ['position' => 5, 'floor' => 3.0]]]],
        ], [])->assertOk();

        $row = DB::table('tr_shelf_item')->where(['shelf_id' => 1, 'item_id' => 2])->first();
        self::assertNotNull($row);
        self::assertSame(5, (int) $row->position);
        self::assertSame(['floor' => 3], \json_decode(\is_string($row->extras) ? $row->extras : '', true), 'the members are normalised and handed to the entry\'s fillUsing()');

        $wrong = $this->sendJsonApiRequest('POST', '/api/tr-shelves/1/relationships/items', [
            'data' => [['type' => 'tr-items', 'id' => '1', 'meta' => ['pivot' => ['position' => 6, 'floor' => 'third']]]],
        ], []);
        $wrong->assertStatus(422);
        self::assertSame('/data/0/meta/pivot/floor', $wrong->json('errors.0.source.pointer'));
        self::assertSame(0, DB::table('tr_shelf_item')->where(['shelf_id' => 1, 'item_id' => 1])->count());
    }

    #[Test]
    public function theStandaloneSchemaTypesAnOpenPivotOnItsLinkage(): void
    {
        $meta = self::find($this->standaloneSchema('tr-shelves'), 'properties', 'relationships', 'properties', 'items', 'properties', 'data', 'items', 'properties', 'meta');
        self::assertIsArray($meta);
        self::assertSame(['position'], \array_keys((array) self::find($meta, 'properties', 'pivot', 'properties')));
        self::assertSame(['integer', 'null'], self::find($meta, 'properties', 'pivot', 'additionalProperties', 'type'));
    }
}
