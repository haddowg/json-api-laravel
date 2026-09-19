<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ErrorCatalog\Unscanned;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;

/**
 * A described error outside every scanned path that the harness names through
 * `JsonApi::register()` — the escape hatch. It sits beside {@see SubscriptionRequired}
 * on purpose: the two are equally unreachable by the scan, and only the registration
 * tells them apart.
 *
 * @internal
 */
final class TenantSuspended extends AbstractJsonApiException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('The tenant is suspended.', self::describe()->status);
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'TENANT_SUSPENDED',
            status: 423,
            title: 'Tenant suspended',
        );
    }

    public function getErrors(): array
    {
        return [self::describe()->toError(detail: $this->getMessage())];
    }
}
