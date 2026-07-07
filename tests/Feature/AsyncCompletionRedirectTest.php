<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\ResponseDeclInMemoryServiceProvider;

/**
 * Pins the read-side async-completion seam: a fetch-one on a `widget-jobs` resource whose
 * {@see \haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument} serializer
 * implements {@see \haddowg\JsonApi\Resource\ResolvesCompletionRedirect} answers `303 See
 * Other` (Location → the produced resource) once the job is done, and a normal `200` while
 * it is still processing.
 *
 * @internal
 */
final class AsyncCompletionRedirectTest extends Orchestra
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            ResponseDeclInMemoryServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('async')]
    public function it_redirects_a_completed_job_with_303_see_other(): void
    {
        $response = $this->get('/api/widget-jobs/done', ['Accept' => 'application/vnd.api+json']);

        $response->assertStatus(303);
        $response->assertHeader('Location', '/api/widgets/1');
    }

    #[Test]
    #[Group('async')]
    public function it_renders_a_still_processing_job_with_200(): void
    {
        $response = $this->get('/api/widget-jobs/pending', ['Accept' => 'application/vnd.api+json']);

        $response->assertStatus(200);
        $response->assertHeaderMissing('Location');
    }
}
