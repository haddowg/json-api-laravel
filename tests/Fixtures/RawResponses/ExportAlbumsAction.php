<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RawResponses;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Exports the album count as a meta document, a CSV string or a PDF stream, whichever the
 * request's `Accept` picks.
 *
 * @internal
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'export',
    methods: ['GET'],
    scope: ActionScope::Collection,
    responds: [new MetaResult(), new RawResult('text/csv', schema: ['type' => 'string']), new RawResult('application/pdf', binary: true)],
)]
final class ExportAlbumsAction implements ActionHandlerInterface
{
    public function handle(ActionContext $context): MetaResponse|RawResponse
    {
        return match ($context->responseMediaType()) {
            'text/csv' => $context->raw("title\nOK Computer\n", headers: ['Content-Disposition' => 'attachment; filename="albums.csv"']),
            'application/pdf' => $context->raw(self::stream('%PDF-1.7 albums')),
            default => $context->meta(['format' => 'json']),
        };
    }

    /**
     * @return resource
     */
    private static function stream(string $contents)
    {
        $stream = \fopen('php://memory', 'r+');
        \assert($stream !== false);
        \fwrite($stream, $contents);
        \rewind($stream);

        return $stream;
    }
}
