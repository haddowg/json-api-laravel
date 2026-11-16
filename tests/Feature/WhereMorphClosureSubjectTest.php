<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\Inbox\ClosureSubjectServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A `WhereMorph` over a `MorphTo` identified only by a closure, on a type the in-memory
 * provider serves, fails `jsonapi:optimize` rather than the first request.
 *
 * @internal
 */
final class WhereMorphClosureSubjectTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ClosureSubjectServiceProvider::class];
    }

    #[Test]
    public function theWarmerReportsTheFilter(): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        self::assertStringContainsString('The filter "subject" on JSON:API type "inbClosureItems" is a WhereMorph over the relation "subject", which no provider can query', $problems);
        self::assertStringContainsString('identifiedByColumns(', $problems);
    }
}
