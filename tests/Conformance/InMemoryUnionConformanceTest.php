<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\UnionInMemoryServiceProvider;
use Workbench\App\Union\Preset;

/**
 * The union conformance cases over the in-memory provider, which stores what the
 * selected member casts to.
 */
#[CoversNothing]
final class InMemoryUnionConformanceTest extends UnionConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return UnionInMemoryServiceProvider::class;
    }

    #[Test]
    public function eachMemberStoresItsOwnPhpType(): void
    {
        $id = $this->createdId(['value' => 3, 'due' => '2026-01-02', 'extra' => ['limit' => '2026-03-04']]);
        $preset = $this->stored($id);

        self::assertSame(3.0, $preset->value);
        self::assertInstanceOf(\DateTimeImmutable::class, $preset->due);
        self::assertSame('2026-01-02', $preset->due->format('Y-m-d'));
        self::assertSame(['limit' => '2026-03-04'], $preset->extra);
    }

    #[Test]
    public function aListMemberStoresEachElementsCast(): void
    {
        $preset = $this->stored($this->createdId(['value' => ['a', 2, true]]));

        self::assertSame(['a', 2.0, true], $preset->value);
    }

    private function stored(string $id): Preset
    {
        $provider = $this->resolve(DataProviderRegistry::class)->forType('presets');
        self::assertInstanceOf(InMemoryDataProvider::class, $provider);
        $preset = $provider->store()->find($id);
        self::assertInstanceOf(Preset::class, $preset);

        return $preset;
    }
}
