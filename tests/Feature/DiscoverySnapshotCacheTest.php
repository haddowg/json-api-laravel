<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\Discovery\DiscoveryScanner;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests the WRITE side of the Phase-0 discovery snapshot cache (PLAN decision 11): when
 * `jsonapi.discovery.cache` names a path, `jsonapi:optimize` writes a `require`-able
 * snapshot the {@see Discovery} loader consumes (so a `route:cache`d app skips the
 * filesystem scan), and `jsonapi:clear` removes it. Loading the written snapshot yields
 * the same resources a live scan does — the cache is a faithful drop-in.
 *
 * @internal
 */
final class DiscoverySnapshotCacheTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    private string $cacheFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheFile = \sys_get_temp_dir() . '/jsonapi-discovery-' . \uniqid() . '.php';
        config(['jsonapi.discovery.cache' => $this->cacheFile]);
    }

    protected function tearDown(): void
    {
        if (\is_file($this->cacheFile)) {
            @\unlink($this->cacheFile);
        }
        parent::tearDown();
    }

    #[Test]
    #[Group('openapi')]
    public function optimize_writes_a_loadable_snapshot(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);

        $this->assertFileExists($this->cacheFile);

        // A fresh Discovery reading only the snapshot (no scan paths) yields the same
        // workbench types a live scan does — the cache is behaviourally identical.
        $cached = new Discovery(new DiscoveryScanner(), [], [], $this->cacheFile);
        $types = \array_map(static fn($descriptor): string => $descriptor->type, $cached->resources());

        $this->assertContains('albums', $types);
        $this->assertContains('artists', $types);
        $this->assertContains('genres', $types);
    }

    #[Test]
    #[Group('openapi')]
    public function clear_removes_the_snapshot(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);
        $this->assertFileExists($this->cacheFile);

        $this->jsonApiArtisan('jsonapi:clear')->assertExitCode(0);
        $this->assertFileDoesNotExist($this->cacheFile);
    }
}
