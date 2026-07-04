<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany as BelongsToManyField;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Tests\Feature\Fixtures\Gadget;
use haddowg\JsonApiLaravel\Tests\Feature\Fixtures\Widget;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Regression guard for the `belongsToMany` related endpoint: the related-collection fetch
 * must render the RELATED table's primary keys, never the pivot row's. The related model's
 * query carries the pivot INNER JOIN but not the relation's `related.*` projection, so an
 * unqualified `select *` hydrates the pivot columns onto the related model — and a pivot
 * column colliding with the related table (here the pivot's own `id`) clobbers the model's
 * key (PDO keeps the last duplicate), rendering the pivot row's id as the JSON:API id. The
 * provider now qualifies the SELECT to `related.*`; this fixture — whose pivot ids (500, 501)
 * are deliberately distinct from the related keys (10, 20) — fails loudly without it.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentBelongsToManyRelatedIdTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('bt_widgets', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
        });
        Schema::create('bt_gadgets', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        // The pivot carries its OWN `id` (the standard `$table->id()` scaffold), which
        // collides with the related `bt_gadgets.id` under a `select *` across the join.
        Schema::create('bt_widget_gadget', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('widget_id');
            $table->unsignedInteger('gadget_id');
            $table->integer('position')->default(0);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('bt_widgets')->insert(['id' => 1, 'label' => 'w1']);
        DB::table('bt_gadgets')->insert([
            ['id' => 10, 'name' => 'g10'],
            ['id' => 20, 'name' => 'g20'],
        ]);
        // Pivot ids (500, 501) are distinct from every gadget id, so a clobber is observable.
        DB::table('bt_widget_gadget')->insert([
            ['id' => 500, 'widget_id' => 1, 'gadget_id' => 10, 'position' => 0],
            ['id' => 501, 'widget_id' => 1, 'gadget_id' => 20, 'position' => 1],
        ]);
    }

    public function test_related_endpoint_renders_the_related_keys_not_the_pivot_ids(): void
    {
        $provider = new EloquentDataProvider(['widgets' => Widget::class, 'gadgets' => Gadget::class]);
        $widget = Widget::query()->findOrFail(1);

        $result = $provider->fetchRelatedCollection(
            'gadgets',
            $widget,
            $this->gadgetsRelation(),
            new CollectionCriteria(new QueryParameters([], [], [], [], [])),
            $this->createStub(JsonApiRequestInterface::class),
        );

        $ids = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(Gadget::class, $item);
            /** @var mixed $key */
            $key = $item->getKey();
            $ids[] = \is_scalar($key) ? (string) $key : '';
        }
        sort($ids);

        self::assertSame(['10', '20'], $ids);
    }

    private function gadgetsRelation(): RelationInterface
    {
        $resource = new class extends AbstractResource {
            public static string $type = 'widgets';

            public function fields(): array
            {
                return [
                    Id::make(),
                    Str::make('label'),
                    BelongsToManyField::make('gadgets', 'gadgets'),
                ];
            }
        };

        $relation = $resource->relationNamed('gadgets');
        self::assertInstanceOf(RelationInterface::class, $relation);

        return $relation;
    }
}
