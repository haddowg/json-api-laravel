<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Validation\Submission;
use Workbench\App\Validation\SubmissionResource;

/**
 * The **in-memory** half of the JSON type conformance wiring: the
 * {@see SubmissionResource} over a writable provider and persister sharing one store,
 * seeded with one submission so a PATCH has a target.
 */
final class ValueTypeInMemoryServiceProvider extends ServiceProvider
{
    public const string SUBMISSIONS = 'workbench.submissions_provider';

    public function register(): void
    {
        JsonApi::register([SubmissionResource::class]);

        $submissions = new InMemoryDataProvider(
            'submissions',
            ['1' => new Submission('1', 'Seed')],
            identify: static function (object $item): string {
                \assert($item instanceof Submission);

                return $item->id;
            },
            assignId: static function (object $item, string $id): void {
                \assert($item instanceof Submission);

                $item->id = $id;
            },
        );
        $this->app->instance(self::SUBMISSIONS, $submissions);
        JsonApi::provider($submissions);
        JsonApi::persister(new InMemoryDataPersister('submissions', $submissions->store(), static fn(): Submission => new Submission()));
    }
}
