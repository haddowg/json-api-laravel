<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\InputMiddleware;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the body-echoing actions on top of the Eloquent workbench.
 *
 * @internal
 */
final class InputMiddlewareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([EchoRawBodyAction::class, EchoDocumentAction::class, EchoNoInputAction::class]);
    }
}
