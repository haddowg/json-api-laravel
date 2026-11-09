<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedOnly\ArchiveImprintAction;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedOnly\ImprintResource;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A custom action mounted on a related-only type stops the application booting, naming
 * the action and the type.
 *
 * @internal
 */
#[CoversNothing]
final class RelatedOnlyBootGuardTest extends Orchestra
{
    private ?\Throwable $bootFailure = null;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (\LogicException $e) {
            $this->bootFailure = $e;
        }
    }

    public function test_an_action_mounted_on_a_related_only_type_fails_the_boot(): void
    {
        self::assertInstanceOf(\LogicException::class, $this->bootFailure);
        self::assertStringContainsString(
            \sprintf('The JSON:API action "archive" on handler %s is mounted on type "imprints", which is related-only', ArchiveImprintAction::class),
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
        JsonApi::register([ImprintResource::class, ArchiveImprintAction::class]);
    }
}
