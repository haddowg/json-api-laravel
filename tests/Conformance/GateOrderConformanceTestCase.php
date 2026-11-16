<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Testing\InteractsWithJsonApi;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\GateOrder\GateLog;
use Workbench\App\Models\User;

/**
 * A caller a policy refuses reaches no lifecycle hook: not the resource's hook methods and
 * not a listener, on any operation, on its own or in an Atomic Operations batch. Runs on the
 * in-memory witness ({@see InMemoryGateOrderConformanceTest}) and Eloquent
 * ({@see EloquentGateOrderConformanceTest}), as the Symfony bundle's suite does.
 */
abstract class GateOrderConformanceTestCase extends Orchestra
{
    use InteractsWithJsonApi;

    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @return class-string
     */
    abstract protected function conformanceServiceProvider(): string;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            $this->conformanceServiceProvider(),
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.atomic_operations.enabled', true);
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        GateLog::reset();
        $this->seedConformanceData();
    }

    protected function tearDown(): void
    {
        GateLog::reset();
        parent::tearDown();
    }

    #[Test]
    public function anAllowedWriteRunsTheHooks(): void
    {
        $this->actingAs($this->user('admin'));

        $this->jsonApi()->withDocument($this->widget('fresh'))->post('/api/gateWidgets')->assertStatus(201);

        foreach (['BeforeSaveEvent', 'beforeSave', 'BeforeCreateEvent', 'beforeCreate', 'afterCreate', 'afterSave'] as $entry) {
            self::assertContains($entry, GateLog::$entries);
        }
    }

    #[Test]
    public function aRefusedCreateRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->withDocument($this->widget('fresh'))->post('/api/gateWidgets'));
    }

    #[Test]
    public function anUpdateRefusedOnTheStoredResourceRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->withDocument($this->widget('renamed', id: '2'))->patch('/api/gateWidgets/2'));
    }

    #[Test]
    public function anUpdateRefusedOnTheChangedResourceRunsNoHook(): void
    {
        // The stored widget is the caller's, so the first check passes; handing it to someone
        // else fails the second.
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->withDocument([
            'data' => ['type' => 'gateWidgets', 'id' => '1', 'attributes' => ['owner' => 'other']],
        ])->patch('/api/gateWidgets/1'));
    }

    #[Test]
    public function anUpdateSettingARelationTheCallerMayNotChangeRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->withDocument([
            'data' => ['type' => 'gateWidgets', 'id' => '1', 'relationships' => ['lockedPartner' => ['data' => null]]],
        ])->patch('/api/gateWidgets/1'));
    }

    #[Test]
    public function aRefusedDeleteRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->delete('/api/gateWidgets/1'));
    }

    #[Test]
    public function aRelationshipWriteRefusedByTheUpdateCheckRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->withDocument(['data' => null])->patch('/api/gateWidgets/2/relationships/partner'));
    }

    #[Test]
    public function aRelationshipWriteRefusedByTheRelationsOwnAbilityRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->withDocument(['data' => null])->patch('/api/gateWidgets/1/relationships/lockedPartner'));
    }

    #[Test]
    public function aRefusedReadRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->get('/api/gateWidgets/3'));
    }

    #[Test]
    public function aRelatedReadRefusedByTheParentsViewCheckRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->get('/api/gateWidgets/3/partner'));
        $this->assertRefused($this->jsonApi()->get('/api/gateWidgets/3/relationships/partner'));
    }

    #[Test]
    public function aRelatedReadRefusedByTheRelationsOwnAbilityRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->get('/api/gateWidgets/1/adminPartner'));
        $this->assertRefused($this->jsonApi()->get('/api/gateWidgets/1/relationships/adminPartner'));
    }

    #[Test]
    public function aRefusedCollectionReadRunsNoHook(): void
    {
        $this->actingAs($this->user('reader'));

        $this->assertRefused($this->jsonApi()->get('/api/gateWidgets'));
    }

    #[Test]
    public function aRefusedActionRunsNoListenerOrHandler(): void
    {
        $this->actingAs($this->user('user'));

        $this->assertRefused($this->jsonApi()->withHeader('Content-Type', 'application/vnd.api+json')->post('/api/gateWidgets/1/-actions/poke'));
    }

    #[Test]
    public function aRefusedAtomicOperationRunsNoHook(): void
    {
        $this->actingAs($this->user('user'));

        $batches = [
            [['op' => 'add', 'data' => ['type' => 'gateWidgets', 'attributes' => ['name' => 'fresh', 'owner' => 'user']]]],
            [['op' => 'update', 'ref' => ['type' => 'gateWidgets', 'id' => '2'], 'data' => ['type' => 'gateWidgets', 'id' => '2', 'attributes' => ['name' => 'renamed']]]],
            [['op' => 'update', 'ref' => ['type' => 'gateWidgets', 'id' => '1'], 'data' => ['type' => 'gateWidgets', 'id' => '1', 'attributes' => ['owner' => 'other']]]],
            [['op' => 'remove', 'ref' => ['type' => 'gateWidgets', 'id' => '1']]],
            [['op' => 'update', 'ref' => ['type' => 'gateWidgets', 'id' => '1', 'relationship' => 'lockedPartner'], 'data' => null]],
        ];

        foreach ($batches as $operations) {
            $this->assertRefused($this->call('POST', '/api/operations', [], [], [], [
                'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
                'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
            ], (string) \json_encode(['atomic:operations' => $operations])));
        }
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function assertRefused(TestResponse $response): void
    {
        $response->assertStatus(403);
        self::assertSame([], GateLog::$entries);
        GateLog::reset();
    }

    protected function user(string $name): User
    {
        return new User([
            'id' => \crc32($name),
            'name' => $name,
            'can_read' => $name !== 'reader',
            'can_write' => false,
            'is_admin' => $name === 'admin',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function widget(string $name, ?string $id = null): array
    {
        $data = ['type' => 'gateWidgets', 'attributes' => ['name' => $name, 'owner' => 'user']];
        if ($id !== null) {
            $data['id'] = $id;
        }

        return ['data' => $data];
    }
}
