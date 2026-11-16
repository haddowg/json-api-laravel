<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\Inbox;
use Workbench\App\Models\InboxItem;
use Workbench\App\Models\InboxLabel;
use Workbench\App\Providers\SingletonEloquentServiceProvider;
use Workbench\App\Singleton\InboxScenario;

/**
 * {@see SingletonConformanceTestCase} on Eloquent over SQLite, plus what only its global
 * scope shows: the inbox model shows each caller only their own row, so an id the resolver
 * names for someone else's inbox is a `404`, and another caller's inbox is never included.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentSingletonConformanceTest extends SingletonConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return SingletonEloquentServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

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
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    protected function seedConformanceData(): void
    {
        $urgent = InboxLabel::query()->create(['name' => 'urgent']);
        InboxLabel::query()->create(['name' => 'later']);

        $ada = Inbox::query()->create(['owner_id' => 1, 'title' => 'Ada inbox']);
        $bob = Inbox::query()->create(['owner_id' => 2, 'title' => 'Bob inbox']);
        $ada->labels()->attach($urgent->id);

        InboxItem::query()->create(['inbox_id' => $ada->id, 'subject' => 'hello']);
        InboxItem::query()->create(['inbox_id' => $ada->id, 'subject' => 'invoice']);
        InboxItem::query()->create(['inbox_id' => $bob->id, 'subject' => 'lunch']);
    }

    #[Test]
    public function theGlobalScopeHidesAnotherCallersResource(): void
    {
        InboxScenario::$inboxByUser['4'] = '1';
        $this->actingAs($this->user(4));

        $this->jsonApi()->get('/api/inbox')->assertStatus(404);
        $this->jsonApi()->withDocument($this->inbox('1', 'Hijacked'))->patch('/api/inbox')->assertStatus(404);
        $this->jsonApi()->get('/api/inboxItems/1?include=inbox')->assertOk()->assertJsonPath('data.relationships.inbox.data', null);

        $this->actingAs($this->user(1));
        $this->jsonApi()->get('/api/inbox')->assertJsonPath('data.attributes.title', 'Ada inbox');
    }
}
