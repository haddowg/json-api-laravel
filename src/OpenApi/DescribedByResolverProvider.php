<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi;

use haddowg\JsonApi\OpenApi\DescribedByResolverInterface;
use haddowg\JsonApi\OpenApi\SpecDescribedByResolver;
use haddowg\JsonApiLaravel\Server\ServerRegistry;

/**
 * Builds the `describedby` resolver a server renders its links with: the
 * server's document URL ({@see DocumentUrlProvider}) paired with the operations its
 * document carries, so a link never points at an operation the document doesn't have.
 *
 * The operations are {@see SpecDescribedByResolver::operationsOf()} of the document, read
 * from the map the {@see DocumentWarmer} writes beside it at `artisan optimize`, so a
 * request reads one small file rather than building the document. Without that map they
 * come from the stored document the docs controller serves, so the two can't disagree,
 * and only without either is the document built, once per process. The map is written
 * back only in debug, like the docs controller's lazy build, so a request never writes to
 * a production filesystem.
 */
final class DescribedByResolverProvider
{
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** @var array<string, array<string, list<string>>> */
    private array $operations = [];

    /**
     * @param \Closure(): DocumentFactory $documents lazy, because the document metadata reads the servers this provider configures
     */
    public function __construct(
        private readonly DocumentUrlProvider $urls,
        private readonly ArtifactStore $store,
        private readonly \Closure $documents,
        private readonly bool $combined = false,
        private readonly bool $debug = false,
    ) {}

    public function forServer(?string $server = null): ?DescribedByResolverInterface
    {
        $server ??= ServerRegistry::DEFAULT_SERVER;
        $url = $this->urls->forServer($server);
        if ($url === null) {
            return null;
        }

        // Built per request: the URL is generated against the request's base path.
        return new SpecDescribedByResolver($url, $this->operations($this->combined ? DocumentFactory::COMBINED_KEY : $server));
    }

    /**
     * @return array<string, list<string>>
     */
    private function operations(string $key): array
    {
        if (isset($this->operations[$key])) {
            return $this->operations[$key];
        }

        $operations = $this->store->readOperations($key);
        if ($operations === null) {
            $operations = $this->operationsOfStoredDocument($key);
            if ($operations === null) {
                $documents = ($this->documents)();
                $operations = SpecDescribedByResolver::operationsOf($key === DocumentFactory::COMBINED_KEY ? $documents->combined() : $documents->forServer($key));
            }

            if ($this->debug) {
                try {
                    $this->store->writeOperations($key, $operations);
                } catch (\RuntimeException) {
                    // An unwritable cache dir only costs later processes the build.
                }
            }
        }

        return $this->operations[$key] = $operations;
    }

    /**
     * The operations of the stored document's JSON, or null when there is none.
     *
     * @return array<string, list<string>>|null
     */
    private function operationsOfStoredDocument(string $key): ?array
    {
        $json = $this->store->read($key);
        if ($json === null) {
            return null;
        }

        try {
            $document = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!$document instanceof \stdClass || !($document->paths ?? null) instanceof \stdClass) {
            return null;
        }

        $operations = [];
        foreach (\get_object_vars($document->paths) as $path => $item) {
            $operations[$path] = $item instanceof \stdClass
                ? \array_values(\array_intersect(\array_keys(\get_object_vars($item)), self::METHODS))
                : [];
        }

        return $operations;
    }
}
