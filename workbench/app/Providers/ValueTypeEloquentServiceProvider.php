<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Submission;
use Workbench\App\Validation\SubmissionResource;

/**
 * The **Eloquent** half of the JSON type conformance wiring: the same
 * {@see SubmissionResource} the in-memory half serves, over the reference Eloquent
 * provider/persister pair.
 */
final class ValueTypeEloquentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([SubmissionResource::class]);

        $modelByType = ['submissions' => Submission::class];

        JsonApi::provider(new EloquentDataProvider($modelByType), priority: -128);
        JsonApi::persister(new EloquentDataPersister($modelByType), priority: -128);
    }
}
