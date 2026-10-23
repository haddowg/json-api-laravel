<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(server: 'partner', readOnly: true)]
final class PartnerVaultResource extends VaultFields {}
