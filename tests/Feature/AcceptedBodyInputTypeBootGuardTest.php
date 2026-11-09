<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ActionDeclarations\IngestBodiesAction;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * An action whose `accepts` reads a media type as a `Document` but names no `inputType`
 * stops the application booting, naming the action and the media type.
 *
 * @internal
 */
#[CoversNothing]
final class AcceptedBodyInputTypeBootGuardTest extends Orchestra
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

    public function test_an_accepted_body_read_as_a_document_without_an_input_type_fails_the_boot(): void
    {
        self::assertInstanceOf(\LogicException::class, $this->bootFailure);
        self::assertStringContainsString(
            \sprintf('The JSON:API action "ingest-bodies" on type "guarded", handled by "%s", reads "application/vnd.api+json" bodies as ActionInput::Document but declares no inputType.', IngestBodiesAction::class),
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
        JsonApi::register([IngestBodiesAction::class]);
    }
}
