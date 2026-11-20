<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\Operation;

/**
 * Exports are always accepted for later processing, their 202 job document declaring
 * the job's status in its meta.
 */
#[AsJsonApiResource(operations: [Operation::Create], create: [new Accepted('tr-jobs')])]
final class TrExportResource extends AbstractResource
{
    public static string $type = 'tr-exports';

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function documentMeta(OperationType $operation): iterable
    {
        return match ($operation) {
            OperationType::CreateAccepted => [Str::make('jobStatus')->required()->example('queued')->extractUsing(static fn(TrJob $job): string => $job->status)],
            default => [],
        };
    }
}
