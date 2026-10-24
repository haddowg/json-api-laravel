<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\Components;
use haddowg\JsonApi\OpenApi\Info;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifactCodec;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ServeArtifactStoreTest extends TestCase
{
    private string $dir;

    private ArtifactStore $store;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/jsonapi-serve-artifact-' . \uniqid();
        $this->store = new ArtifactStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->store->clear();
        @\rmdir($this->dir);
    }

    #[Test]
    public function a_serve_artifact_round_trips(): void
    {
        $this->store->writeServeArtifact('default', ServeArtifact::of(new OpenApi(new Info('Catalog', '1.0.0'), new Components())));

        $artifact = $this->store->readServeArtifact('default');

        $this->assertNotNull($artifact);
        $this->assertSame('Catalog', $artifact->document->info->title);
    }

    #[Test]
    public function the_artifact_records_this_package_beside_core(): void
    {
        $this->store->writeServeArtifact('default', ServeArtifact::of(new OpenApi(new Info('Catalog', '1.0.0'), new Components())));

        $fingerprint = \explode("\n", $this->serveBytes())[1] ?? '';

        $this->assertStringStartsWith(ServeArtifactCodec::PACKAGE . '@', $fingerprint);
        $this->assertStringContainsString(';' . ArtifactStore::PACKAGE . '@', $fingerprint);
    }

    #[Test]
    public function an_artifact_from_another_installed_version_is_ignored(): void
    {
        $this->store->writeServeArtifact('default', ServeArtifact::of(new OpenApi(new Info('Catalog', '1.0.0'), new Components())));

        // The fingerprint is the second line of the bytes; an earlier deploy's names other versions.
        $lines = \explode("\n", $this->serveBytes(), 3);
        $lines[1] = ServeArtifactCodec::PACKAGE . '@0.0.1#old;' . ArtifactStore::PACKAGE . '@0.0.1#old';
        \file_put_contents($this->dir . '/default.serve', \implode("\n", $lines));

        $this->assertNull($this->store->readServeArtifact('default'));
    }

    #[Test]
    public function an_artifact_written_without_this_package_in_the_fingerprint_is_ignored(): void
    {
        $coreOnly = new ArtifactStore($this->dir, new ServeArtifactCodec());
        $coreOnly->writeServeArtifact('default', ServeArtifact::of(new OpenApi(new Info('Catalog', '1.0.0'), new Components())));

        $this->assertNotNull($coreOnly->readServeArtifact('default'));
        $this->assertNull($this->store->readServeArtifact('default'));
    }

    #[Test]
    public function a_serve_artifact_can_be_forgotten(): void
    {
        $this->store->writeServeArtifact('default', ServeArtifact::of(new OpenApi(new Info('Catalog', '1.0.0'), new Components())));

        $this->store->forgetServeArtifact('default');
        $this->store->forgetServeArtifact('admin');

        $this->assertNull($this->store->readServeArtifact('default'));
    }

    #[Test]
    public function a_hash_is_written_beside_each_artifact(): void
    {
        $hash = $this->store->writeWithHash('default', '{}');
        $schemaHash = $this->store->writeSchemaAggregateWithHash('default', '{"a":{}}');

        $this->assertSame(EntityTag::hash('{}'), $hash);
        $this->assertSame($hash, $this->store->readHash('default'));
        $this->assertSame($schemaHash, $this->store->readSchemaAggregateHash('default'));
        $this->assertNull($this->store->readHash('admin'));
    }

    private function serveBytes(): string
    {
        $bytes = \file_get_contents($this->dir . '/default.serve');
        $this->assertIsString($bytes);

        return $bytes;
    }
}
