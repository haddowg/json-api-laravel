<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The sparse-by-default field conformance witness (core ADR 0117), run against both
 * providers — the Laravel twin of the Symfony bundle's `SparseByDefaultFieldTest`: the
 * `sparseWidgets` resource's `expensiveScore` attribute (marked
 * {@see \haddowg\JsonApi\Resource\Field\AbstractFieldBuilder::sparseByDefault()}) is omitted
 * from the default response and rendered **only** when the client names it in a
 * `fields[sparseWidgets]` member, proving core's opt-in visibility tier flows through
 * the serializer → transformer → response stack end-to-end over HTTP. The cheap `name`
 * attribute always renders, and naming the sparse field is accepted (it stays a fully
 * declared member) rather than rejected as an unknown fieldset.
 */
abstract class SparseByDefaultConformanceTestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

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
        $config->set('jsonapi.base_uri', 'http://localhost/api');
    }

    protected function seedConformanceData(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedConformanceData();
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function aSparseByDefaultFieldIsOmittedFromTheDefaultResponse(): void
    {
        $attributes = $this->attributesOf('/api/sparseWidgets/1');

        self::assertSame('Gadget', $attributes['name'] ?? null);
        self::assertArrayNotHasKey('expensiveScore', $attributes);
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function aSparseByDefaultFieldRendersWhenExplicitlyRequested(): void
    {
        $attributes = $this->attributesOf('/api/sparseWidgets/1?fields[sparseWidgets]=name,expensiveScore');

        self::assertSame('Gadget', $attributes['name'] ?? null);
        self::assertSame(99, $attributes['expensiveScore'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-sparse-fieldsets')]
    public function aSparseByDefaultFieldStaysAbsentWhenAnotherFieldIsRequested(): void
    {
        // Naming only `name` keeps the sparse field absent — it renders ONLY when named.
        $attributes = $this->attributesOf('/api/sparseWidgets/1?fields[sparseWidgets]=name');

        self::assertSame('Gadget', $attributes['name'] ?? null);
        self::assertArrayNotHasKey('expensiveScore', $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesOf(string $path): array
    {
        $response = $this->get($path, ['Accept' => self::MEDIA_TYPE]);
        $response->assertOk();
        $response->assertHeader('Content-Type', self::MEDIA_TYPE);

        $attributes = $response->json('data.attributes');
        self::assertIsArray($attributes);

        /** @var array<string, mixed> $attributes */
        return $attributes;
    }
}
