<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\WrittenNumbers\WrittenNumbersServiceProvider;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * JSON can't tell `2` from `2.0`. A whole number written where an integer is declared is
 * an `int`, and an integer written where a number is declared a `float`, before the
 * rules compare it, a hook receives it or the store keeps it.
 *
 * @internal
 */
#[CoversNothing]
final class WrittenNumbersTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, WrittenNumbersServiceProvider::class];
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

    #[Test]
    public function anIntegerInASetTakesAWholeFloat(): void
    {
        $response = $this->send('/api/numberCards', ['picked' => 2.0, 'ranked' => 2.0, 'stats' => ['plays' => 2.0]]);

        $response->assertCreated();
        self::assertSame(2, $response->json('data.attributes.picked'));
        self::assertSame(2, $response->json('data.attributes.ranked'));
        self::assertSame(['plays' => 2], $response->json('data.attributes.stats'));
    }

    #[Test]
    public function anIntegerOutsideASetRefusesAWholeFloat(): void
    {
        $this->send('/api/numberCards', ['avoided' => 2.0])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/data/attributes/avoided');
    }

    #[Test]
    public function aNumberInASetTakesAnInteger(): void
    {
        $this->send('/api/numberCards', ['weight' => 2, 'whole' => 2])->assertCreated();
        $this->send('/api/numberCards', ['whole' => 2.0])->assertCreated();
    }

    #[Test]
    public function aNumberOutsideASetRefusesAnInteger(): void
    {
        $this->send('/api/numberCards', ['avoidedWeight' => 2])->assertStatus(422)->assertJsonPath('errors.0.source.pointer', '/data/attributes/avoidedWeight');
    }

    #[Test]
    public function anIntegerListStoresInts(): void
    {
        $response = $this->send('/api/numberCards', ['counts' => [5.0, 6]]);

        $response->assertCreated();
        self::assertSame([5, 6], $response->json('data.attributes.counts'));
    }

    #[Test]
    public function anIntegerListsUniquenessSeesAWholeFloatAsItsInt(): void
    {
        $this->send('/api/numberCards', ['counts' => [1, 1.0]])->assertStatus(422);
    }

    #[Test]
    public function aHookReceivesAnInt(): void
    {
        $response = $this->send('/api/numberCards', ['hooked' => 5.0]);

        $response->assertCreated();
        self::assertSame('int', $response->json('data.attributes.hookedType'));
    }

    #[Test]
    public function anAtomicOperationIsNormalizedToo(): void
    {
        $mediaType = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $response = $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], \json_encode(['atomic:operations' => [['op' => 'add', 'data' => ['type' => 'numberCards', 'attributes' => ['picked' => 3.0, 'hooked' => 4.0]]]]], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));

        $response->assertOk();
        self::assertSame(3, $response->json('atomic:results.0.data.attributes.picked'));
        self::assertSame('int', $response->json('atomic:results.0.data.attributes.hookedType'));
    }

    #[Test]
    public function anAtomicOperationsUniquenessSeesAWholeFloatAsItsInt(): void
    {
        $mediaType = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => $mediaType,
            'CONTENT_TYPE' => $mediaType,
        ], \json_encode(['atomic:operations' => [['op' => 'add', 'data' => ['type' => 'numberCards', 'attributes' => ['counts' => [1, 1.0]]]]]], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION))
            ->assertStatus(422);
    }

    #[Test]
    public function anActionsInputUniquenessSeesAWholeFloatAsItsInt(): void
    {
        $this->send('/api/numberCards/-actions/score', ['counts' => [1, 1.0]])->assertStatus(422);
    }

    #[Test]
    public function anActionsInputIsNormalized(): void
    {
        $response = $this->send('/api/numberCards/-actions/score', ['picked' => 2.0, 'weight' => 2, 'hooked' => 4.0, 'counts' => [1.0], 'weights' => [1, 2.5]]);

        $response->assertOk();
        self::assertSame(
            ['picked' => 'int', 'weight' => 'float', 'hooked' => 'int', 'counts' => ['int'], 'weights' => ['float', 'float']],
            $response->json('meta'),
        );
    }

    /**
     * POSTs the attributes keeping a whole float's `.0`, as a client writing `2.0` sends it.
     *
     * @param array<string, mixed> $attributes
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(string $uri, array $attributes): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
            'CONTENT_TYPE' => 'application/vnd.api+json',
        ], \json_encode(['data' => ['type' => 'numberCards', 'attributes' => $attributes]], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
    }
}
