<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Resource\DocumentMetaMisdeclared;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\TypedMeta\TmClashResource;

/**
 * A collection's declared meta naming a member core writes stops the application booting.
 *
 * @internal
 */
#[CoversNothing]
final class TypedDocumentMetaBootGuardTest extends Orchestra
{
    private ?\Throwable $bootFailure = null;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (DocumentMetaMisdeclared $e) {
            $this->bootFailure = $e;
        }
    }

    public function test_a_collection_member_core_writes_fails_the_boot(): void
    {
        self::assertInstanceOf(DocumentMetaMisdeclared::class, $this->bootFailure);
        self::assertStringContainsString(
            'The top-level meta of the "tm-clashes" FetchCollection document declares the member "page", which core writes into every collection document.',
            $this->bootFailure->getMessage(),
        );
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        JsonApi::register([TmClashResource::class]);
    }
}
