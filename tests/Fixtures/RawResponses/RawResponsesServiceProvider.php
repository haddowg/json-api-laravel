<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RawResponses;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class RawResponsesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ExportAlbumsAction::class, MislabelledExportAction::class]);
    }
}
