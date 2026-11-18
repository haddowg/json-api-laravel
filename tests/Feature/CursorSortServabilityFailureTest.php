<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\CursorSortServability\CursorSortServabilityServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A cursor-paged collection whose sort can't key the page fails `jsonapi:optimize`, where
 * a request ordering by it would otherwise answer `500`.
 *
 * @internal
 */
final class CursorSortServabilityFailureTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            CursorSortServabilityServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theWarmerReportsASortWithoutAKeysetValue(): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        $this->assertStringContainsString('The collection of type "unkeyableShelves" on server "default" pages by cursor, but its sort "unkeyable"', $problems);
        $this->assertStringContainsString('KeysetSortInterface', $problems);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theWarmerReportsAnEloquentArmThatCannotKeyTheCursor(): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        $this->assertStringContainsString('The collection of type "scoreTeams" on server "default" pages by cursor, but', $problems);
        $this->assertStringContainsString('cannot order a cursor page by its sort "topScore"', $problems);
        $this->assertStringContainsString('EloquentKeysetSortArmInterface', $problems);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function optimizeFailsTheDeployOnAnUnkeyableCursorSort(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(1);
    }
}
