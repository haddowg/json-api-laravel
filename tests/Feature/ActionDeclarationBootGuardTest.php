<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionDeclarations\ExportAllAction;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionDeclarations\ExportOneAction;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * An action declaration the OpenAPI projection would reject stops the application booting,
 * since registering the routes runs discovery, rather than waiting for the first document
 * request.
 *
 * @internal
 */
#[CoversNothing]
final class ActionDeclarationBootGuardTest extends Orchestra
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

    public function test_one_action_path_at_both_scopes_fails_the_boot(): void
    {
        self::assertInstanceOf(\LogicException::class, $this->bootFailure);
        self::assertStringContainsString(ExportAllAction::class, $this->bootFailure->getMessage());
        self::assertStringContainsString('both the resource and the collection scope', $this->bootFailure->getMessage());
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
        JsonApi::register([ExportOneAction::class, ExportAllAction::class]);
    }
}
