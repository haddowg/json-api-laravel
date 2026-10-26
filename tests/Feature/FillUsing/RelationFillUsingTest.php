<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\FillUsing;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing\FillUsingServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing\Pin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A relation's `fillUsing()` hook writes its linkage on every write path: a create, an update,
 * the relationship endpoints and an atomic batch. The `pins` relations have no Eloquent relation
 * behind them, so the hook is the only way their columns change.
 *
 * Seed: pin 1 "Todo" points at board 1 and carries tag 1.
 *
 * @internal
 */
#[CoversNothing]
final class RelationFillUsingTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string ATOMIC = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, FillUsingServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['fu_boards', 'fu_cards', 'fu_tags'] as $table) {
            Schema::create($table, static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name');
            });
        }
        Schema::create('fu_pins', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('label');
            $table->string('target_kind')->nullable();
            $table->unsignedInteger('target_key')->nullable();
            $table->json('tag_list')->nullable();
        });

        DB::table('fu_boards')->insert([['id' => 1, 'name' => 'Roadmap'], ['id' => 2, 'name' => 'Backlog']]);
        DB::table('fu_cards')->insert([['id' => 1, 'name' => 'Login']]);
        DB::table('fu_tags')->insert([['id' => 1, 'name' => 'urgent'], ['id' => 2, 'name' => 'ux'], ['id' => 3, 'name' => 'api']]);
        DB::table('fu_pins')->insert(['id' => 1, 'label' => 'Todo', 'target_kind' => 'board', 'target_key' => 1, 'tag_list' => '[1]']);
    }

    #[Test]
    public function aCreateFillsBothRelationsThroughTheirHooks(): void
    {
        $created = $this->write('POST', '/api/pins', ['data' => [
            'type' => 'pins',
            'attributes' => ['label' => 'Spec'],
            'relationships' => [
                'target' => ['data' => ['type' => 'cards', 'id' => '1']],
                'tags' => ['data' => [['type' => 'tags', 'id' => '3'], ['type' => 'tags', 'id' => '2']]],
            ],
        ]]);

        $created->assertCreated();
        $created->assertJsonPath('data.relationships.target.data', ['type' => 'cards', 'id' => '1']);
        $this->assertPin($this->intId($created->json('data.id')), 'card', 1, [2, 3]);
    }

    #[Test]
    public function anUpdateFillsBothRelationsThroughTheirHooks(): void
    {
        $this->write('PATCH', '/api/pins/1', ['data' => [
            'type' => 'pins',
            'id' => '1',
            'relationships' => [
                'target' => ['data' => ['type' => 'boards', 'id' => '2']],
                'tags' => ['data' => [['type' => 'tags', 'id' => '2']]],
            ],
        ]])->assertOk()->assertJsonPath('data.relationships.target.data', ['type' => 'boards', 'id' => '2']);

        $this->assertPin(1, 'board', 2, [2]);
    }

    #[Test]
    public function theToOneRelationshipEndpointFillsThroughTheHook(): void
    {
        $this->write('PATCH', '/api/pins/1/relationships/target', ['data' => ['type' => 'cards', 'id' => '1']])
            ->assertOk()
            ->assertJsonPath('data', ['type' => 'cards', 'id' => '1']);
        $this->assertPin(1, 'card', 1, [1]);

        $this->write('PATCH', '/api/pins/1/relationships/target', ['data' => null])->assertOk();
        $this->assertPin(1, null, null, [1]);
    }

    #[Test]
    public function theToManyRelationshipEndpointsFillThroughTheHookWithTheirMode(): void
    {
        $this->write('POST', '/api/pins/1/relationships/tags', ['data' => [['type' => 'tags', 'id' => '3']]])->assertOk();
        $this->assertPin(1, 'board', 1, [1, 3]);

        $this->write('DELETE', '/api/pins/1/relationships/tags', ['data' => [['type' => 'tags', 'id' => '1']]])->assertOk();
        $this->assertPin(1, 'board', 1, [3]);

        $this->write('PATCH', '/api/pins/1/relationships/tags', ['data' => [['type' => 'tags', 'id' => '2']]])
            ->assertOk()
            ->assertJsonPath('data', [['type' => 'tags', 'id' => '2']]);
        $this->assertPin(1, 'board', 1, [2]);
    }

    #[Test]
    public function anAtomicBatchFillsThroughTheHooks(): void
    {
        $body = (string) \json_encode(['atomic:operations' => [
            ['op' => 'update', 'ref' => ['type' => 'pins', 'id' => '1', 'relationship' => 'target'], 'data' => ['type' => 'cards', 'id' => '1']],
            ['op' => 'add', 'ref' => ['type' => 'pins', 'id' => '1', 'relationship' => 'tags'], 'data' => [['type' => 'tags', 'id' => '2']]],
            ['op' => 'add', 'data' => ['type' => 'pins', 'attributes' => ['label' => 'New'], 'relationships' => ['target' => ['data' => ['type' => 'boards', 'id' => '2']]]]],
        ]]);

        $response = $this->call('POST', '/api/operations', [], [], [], ['HTTP_ACCEPT' => self::ATOMIC, 'CONTENT_TYPE' => self::ATOMIC], $body);

        $response->assertOk();
        $this->assertPin(1, 'card', 1, [1, 2]);
        $this->assertPin($this->intId($response->json('atomic:results.2.data.id')), 'board', 2, null);
    }

    private function intId(mixed $id): int
    {
        self::assertIsString($id);

        return (int) $id;
    }

    /**
     * @param list<int>|null $tags
     */
    private function assertPin(int $id, ?string $kind, ?int $key, ?array $tags): void
    {
        $pin = Pin::query()->findOrFail($id);
        self::assertSame($kind, $pin->target_kind);
        self::assertSame($key, $pin->target_key === null ? null : (int) $pin->target_key);
        self::assertSame($tags, $pin->tag_list);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function write(string $method, string $uri, array $body): TestResponse
    {
        return $this->json($method, $uri, $body, ['Accept' => self::MEDIA_TYPE, 'Content-Type' => self::MEDIA_TYPE]);
    }
}
