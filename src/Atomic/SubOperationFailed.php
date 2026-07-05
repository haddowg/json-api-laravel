<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApi\Server\ServerInterface;

/**
 * Adapts an {@see ErrorResponse} a sub-operation *returned* (rather than threw) into a
 * {@see JsonApiExceptionInterface} the {@see \haddowg\JsonApi\Atomic\AtomicLoop} can catch,
 * pointer-prefix with the failing operation index, and render as the rolled-back error
 * document — the Laravel twin of the bundle's `Atomic\SubOperationFailed`.
 *
 * Most failures inside a CRUD arm throw a typed exception (validation `422`, authz `403`,
 * mutability `403`) that propagates out of `handle()` unchanged. But a missing
 * update/delete target and an unknown relationship are RETURNED as an {@see ErrorResponse}
 * (a `404`), not thrown. Inside an atomic batch that returned error must still abort the
 * whole batch, so the executor re-raises it through this adapter, reconstructing the
 * {@see Error} value objects from the rendered document so the loop prefixes their pointers
 * exactly as it does for a thrown exception.
 */
final class SubOperationFailed extends \RuntimeException implements JsonApiExceptionInterface
{
    /**
     * @var list<Error>
     */
    private readonly array $errors;

    private readonly int $status;

    public function __construct(ErrorResponse $response, ServerInterface $server, \Psr\Http\Message\ServerRequestInterface $request)
    {
        $psr = $response->toPsrResponse($server, $request);
        $this->status = $psr->getStatusCode();

        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode((string) $psr->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        $this->errors = self::rebuildErrors($decoded['errors'] ?? []);

        parent::__construct('An atomic sub-operation failed.');
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * Rebuilds the {@see Error} value objects from a rendered `errors` array so the loop can
     * decorate their `source.pointer` — preserving status/code/title/detail and
     * reconstructing the single-member `source` (pointer / parameter / header).
     *
     * @return list<Error>
     */
    private static function rebuildErrors(mixed $errors): array
    {
        if (!\is_array($errors)) {
            return [];
        }

        $rebuilt = [];
        foreach ($errors as $error) {
            if (!\is_array($error)) {
                continue;
            }

            $rebuilt[] = new Error(
                id: self::stringMember($error, 'id'),
                status: self::stringMember($error, 'status'),
                code: self::stringMember($error, 'code'),
                title: self::stringMember($error, 'title'),
                detail: self::stringMember($error, 'detail'),
                source: self::rebuildSource($error['source'] ?? null),
            );
        }

        return $rebuilt;
    }

    /**
     * Rebuilds an {@see ErrorSource} from a rendered `source` member, preserving the single
     * member it carries (pointer / parameter / header); `null` when absent.
     */
    private static function rebuildSource(mixed $source): ?ErrorSource
    {
        if (!\is_array($source)) {
            return null;
        }

        $pointer = self::stringMember($source, 'pointer');
        if ($pointer !== '') {
            return ErrorSource::fromPointer($pointer);
        }

        $parameter = self::stringMember($source, 'parameter');
        if ($parameter !== '') {
            return ErrorSource::fromParameter($parameter);
        }

        $header = self::stringMember($source, 'header');
        if ($header !== '') {
            return ErrorSource::fromHeader($header);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    private static function stringMember(array $array, string $key): string
    {
        $value = $array[$key] ?? null;

        return \is_string($value) ? $value : '';
    }
}
