<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ErrorScope\Scanned;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorScope;

/**
 * An application's own described error that only the album update raises, so its
 * descriptor scopes it there. Discovery finds it under the one scanned directory, and
 * the scope it declares reaches the document unchanged.
 *
 * @internal
 */
final class AlbumLocked extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The album is locked for editing.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ALBUM_LOCKED',
            status: 409,
            title: 'Album locked',
            scope: ErrorScope::on('albums', 'update'),
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
