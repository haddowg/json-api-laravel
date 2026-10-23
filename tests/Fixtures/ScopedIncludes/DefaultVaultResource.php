<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

#[AsJsonApiResource(operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Create, Operation::Update])]
final class DefaultVaultResource extends VaultFields {}
