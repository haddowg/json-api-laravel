<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ErrorScope;

use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ScopedErrorSource;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ErrorScope\Unscanned\PaymentRequired;
use Illuminate\Support\ServiceProvider;

/**
 * Wires both ways an error is scoped to the operations that raise it:
 *
 *  - `ErrorScope/Scanned` is added to discovery, so {@see Scanned\AlbumLocked} is
 *    catalogued with the scope its own descriptor declares;
 *  - {@see PaymentRequired}, which declares none and no scan reaches, is catalogued by a
 *    `ScopedErrorSource` bound under the error-source tag, which scopes it to the album
 *    delete.
 *
 * @internal
 */
final class ErrorScopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::discover([__DIR__ . '/Scanned']);

        $this->app->singleton(
            'app.jsonapi.payment_required',
            static fn(): ScopedErrorSource => new ScopedErrorSource(ErrorScope::on('albums', 'delete'), PaymentRequired::class),
        );
        $this->app->tag('app.jsonapi.payment_required', JsonApiServiceProvider::ERROR_SOURCE_TAG);
    }
}
