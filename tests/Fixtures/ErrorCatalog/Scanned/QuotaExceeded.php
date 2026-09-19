<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ErrorCatalog\Scanned;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * An application's own described error, declared inside the one directory the harness
 * adds to `jsonapi.discovery.paths` — so discovery catalogues it with no registration.
 *
 * @internal
 */
final class QuotaExceeded extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The account has used its request quota for this period.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'QUOTA_EXCEEDED',
            status: 429,
            title: 'Quota exceeded',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
