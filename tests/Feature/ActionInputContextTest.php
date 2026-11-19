<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionInputContext\ActionInputContextServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * An action validates its input document in the context it hydrates it in: a `PATCH`
 * in the update context, anything else in the create context.
 *
 * @internal
 */
#[CoversNothing]
final class ActionInputContextTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ActionInputContextServiceProvider::class];
    }

    #[Test]
    public function aPatchWritesAFieldReadOnlyOnlyOnCreate(): void
    {
        $response = $this->record('PATCH', ['code' => 'A1']);

        $response->assertOk();
        self::assertSame(['code' => 'A1', 'note' => 'unset'], $response->json('meta'));
    }

    #[Test]
    public function aPatchChecksTheTypeOfAFieldReadOnlyOnlyOnCreate(): void
    {
        $response = $this->record('PATCH', ['code' => 5]);

        $response->assertStatus(422);
        self::assertSame('/data/attributes/code', $response->json('errors.0.source.pointer'));
        self::assertCount(1, (array) $response->json('errors'));
    }

    #[Test]
    public function aPostLeavesAFieldReadOnlyOnCreateUnwritten(): void
    {
        $response = $this->record('POST', ['code' => 'A1']);

        $response->assertOk();
        self::assertSame(['code' => 'unset', 'note' => 'unset'], $response->json('meta'));
    }

    #[Test]
    public function aPatchTakesNullWhereOnlyAnUpdateMay(): void
    {
        $response = $this->record('PATCH', ['note' => null]);

        $response->assertOk();
        self::assertSame(['code' => 'unset', 'note' => null], $response->json('meta'));
    }

    #[Test]
    public function aPostRefusesNullWhereOnlyAnUpdateMayTakeIt(): void
    {
        $response = $this->record('POST', ['note' => null]);

        $response->assertStatus(422);
        self::assertSame('/data/attributes/note', $response->json('errors.0.source.pointer'));
        self::assertCount(1, (array) $response->json('errors'));
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function record(string $method, array $attributes): TestResponse
    {
        // An update's resource object names its id, and a create's names none.
        $data = ['type' => 'contextNotes', 'attributes' => $attributes] + ($method === 'PATCH' ? ['id' => '1'] : []);

        return $this->call($method, '/api/contextNotes/-actions/record', [], [], [], [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
            'CONTENT_TYPE' => 'application/vnd.api+json',
        ], \json_encode(['data' => $data], \JSON_THROW_ON_ERROR));
    }
}
