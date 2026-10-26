<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Action;

use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\RawResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Action\ActionScope;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use Workbench\App\MusicCatalog\OpenApi\AlbumCatalogueStats;

/**
 * `GET /albums/-actions/export` answers in the format the client's `Accept` asks for: a
 * JSON:API meta document, a CSV of the albums, a PDF report or plain JSON counts. The CSV and
 * PDF come back as downloads. Byte-compat twin of the Symfony example's `ExportAlbums`; a
 * released album counts as published.
 */
#[AsJsonApiAction(
    type: 'albums',
    path: 'export',
    methods: ['GET'],
    scope: ActionScope::Collection,
    responds: [
        new MetaResult(),
        new RawResult('text/csv', schema: ['type' => 'string'], headers: ['Content-Disposition' => 'Names the downloaded file.'], example: "id,title\n1,Kind of Blue\n"),
        new RawResult('application/pdf', binary: true, headers: ['Content-Disposition' => 'Names the downloaded file.']),
        new RawResult('application/json', schema: AlbumCatalogueStats::class),
    ],
    tags: ['Catalog'],
    summary: 'Export the album catalogue',
    description: 'Exports every album in the format the Accept header asks for: a JSON:API meta document with counts, a CSV of ids and titles, a PDF report, or the counts as plain JSON.',
)]
final class ExportAlbums implements ActionHandlerInterface
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    public function handle(ActionContext $context): MetaResponse|RawResponse
    {
        $albums = [];
        foreach ($this->providers->forType('albums')->fetchCollection('albums', new CollectionCriteria(new QueryParameters([], [], [], [], [])))->items as $album) {
            if (\is_object($album)) {
                $albums[] = $album;
            }
        }
        \usort($albums, static fn(object $a, object $b): int => (int) self::scalar($a, 'id') <=> (int) self::scalar($b, 'id'));

        $published = \count(\array_filter($albums, static fn(object $album): bool => self::scalar($album, 'status') === 'released'));
        $counts = ['published' => $published, 'unpublished' => \count($albums) - $published];

        return match ($context->responseMediaType()) {
            'text/csv' => $context->raw(self::csv($albums), headers: ['Content-Disposition' => 'attachment; filename="albums.csv"']),
            'application/pdf' => $context->raw(self::pdf(\count($albums)), headers: ['Content-Disposition' => 'attachment; filename="albums.pdf"']),
            'application/json' => $context->raw(\json_encode($counts, \JSON_THROW_ON_ERROR)),
            default => $context->meta(['albums' => $counts]),
        };
    }

    /**
     * @param list<object> $albums
     */
    private static function csv(array $albums): string
    {
        $lines = ['id,title'];
        foreach ($albums as $album) {
            $lines[] = self::scalar($album, 'id') . ',"' . \str_replace('"', '""', self::scalar($album, 'title')) . '"';
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * A placeholder report streamed from memory, standing in for a real PDF renderer.
     *
     * @return resource
     */
    private static function pdf(int $count)
    {
        $stream = \fopen('php://memory', 'r+');
        \assert(\is_resource($stream));
        \fwrite($stream, "%PDF-1.7\n% " . $count . " albums\n%%EOF\n");
        \rewind($stream);

        return $stream;
    }

    private static function scalar(object $album, string $member): string
    {
        $value = Accessor::get($album, $member);

        return \is_scalar($value) ? (string) $value : '';
    }
}
