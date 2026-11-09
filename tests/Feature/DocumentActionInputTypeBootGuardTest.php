<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionDeclarations\RetitleAction;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A `Document` action that names no `inputType` stops the application booting, naming the
 * action, its type and its handler.
 *
 * @internal
 */
#[CoversNothing]
final class DocumentActionInputTypeBootGuardTest extends Orchestra
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

    public function test_a_document_action_without_an_input_type_fails_the_boot(): void
    {
        self::assertInstanceOf(\LogicException::class, $this->bootFailure);
        self::assertStringContainsString(
            \sprintf('The JSON:API action "retitle" on type "guarded", handled by "%s", takes input: ActionInput::Document but declares no inputType.', RetitleAction::class),
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
        JsonApi::register([RetitleAction::class]);
    }
}
