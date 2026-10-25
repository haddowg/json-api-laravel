<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

#[AsJsonApiResource(server: 'partner', operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Update])]
final class PartnerChestResource extends ChestFields {}
