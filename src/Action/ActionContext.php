<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\ResolvingServerInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The resolved context handed to an {@see ActionHandlerInterface::handle()} (the Laravel
 * twin of the bundle's `Action\ActionContext`). It carries everything the handler needs
 * without threading the server: the resolving {@see ResolvingServerInterface}, the
 * {@see ActionDescriptor}, the resolved {@see $entity} (resource scope; `null` for
 * collection scope), the hydrated {@see $input} (Document mode; `null` otherwise), the
 * {@see JsonApiRequestInterface} (always — the raw body + uploaded files for a Raw-input
 * action) and the {@see QueryParameters}.
 *
 * {@see serializer()} resolves the `outputType` serializer once, and the convenience
 * factories {@see data()}/{@see meta()}/{@see noContent()} pre-wire it, so the handler
 * returns a response in one call.
 */
final readonly class ActionContext
{
    /**
     * The request attribute carrying the {@see ActionInput} case name the body was read in.
     *
     * @internal
     */
    public const string INPUT_ATTRIBUTE = '_jsonapi_action_input';

    /**
     * The request attribute carrying the request body's media type, or null without one.
     *
     * @internal
     */
    public const string MEDIA_TYPE_ATTRIBUTE = '_jsonapi_request_media_type';

    public function __construct(
        private ResolvingServerInterface $server,
        private ActionDescriptor $descriptor,
        private ?object $entity,
        private ?object $input,
        private JsonApiRequestInterface $request,
        private QueryParameters $queryParameters,
        private string $responseMediaType = 'application/vnd.api+json',
    ) {}

    /**
     * The media type the response must use, picked from the action's declared responses by
     * the request's `Accept` header: `application/vnd.api+json` for a JSON:API document, or
     * a raw result's media type such as `text/csv`.
     */
    public function responseMediaType(): string
    {
        return $this->responseMediaType;
    }

    /**
     * A response whose body is not a JSON:API document, in the negotiated media type unless
     * `$contentType` names another one the action declares and the client accepts.
     *
     * @param string|resource|StreamInterface $body
     * @param array<string, string>           $headers
     */
    public function raw(mixed $body, ?string $contentType = null, int $status = 200, array $headers = []): RawResponse
    {
        return new RawResponse($body, $contentType ?? $this->responseMediaType, $status, $headers);
    }

    /**
     * The resolved entity for a resource-scope action (fetched via the type's
     * DataProvider before the handler runs), or `null` for a collection-scope action.
     */
    public function entity(): ?object
    {
        return $this->entity;
    }

    /**
     * The hydrated input object for a {@see ActionInput::Document} action, or `null` for
     * a {@see ActionInput::None}/{@see ActionInput::Raw} action.
     */
    public function input(): ?object
    {
        return $this->input;
    }

    /**
     * The originating JSON:API request — always present; for a {@see ActionInput::Raw}
     * action this exposes the raw body and the uploaded files (via the PSR-7
     * `getBody()`/`getUploadedFiles()` it extends).
     */
    public function request(): JsonApiRequestInterface
    {
        return $this->request;
    }

    /**
     * The media type of the request body, lowercased and without parameters, such as
     * `text/csv`; null when the request declared none. For an action that lists several
     * `accepts` entries, this is the one the body matched.
     */
    public function requestMediaType(): ?string
    {
        $mediaType = $this->request->getAttribute(self::MEDIA_TYPE_ATTRIBUTE);

        return \is_string($mediaType) ? $mediaType : null;
    }

    public function queryParameters(): QueryParameters
    {
        return $this->queryParameters;
    }

    public function server(): ResolvingServerInterface
    {
        return $this->server;
    }

    public function descriptor(): ActionDescriptor
    {
        return $this->descriptor;
    }

    /**
     * The serializer for the action's `outputType` (defaulting to the mount type) — the
     * serializer every {@see DataResponse}/{@see MetaResponse} the handler returns should
     * render through.
     */
    public function serializer(): SerializerInterface
    {
        return $this->server->serializerFor($this->descriptor->outputType);
    }

    /**
     * A {@see DataResponse} rendering `$data` through the `outputType` serializer — a
     * single resource document for an object, a collection document for an iterable.
     *
     * @param object|iterable<mixed> $data
     */
    public function data(object|iterable $data): DataResponse
    {
        $serializer = $this->serializer();

        return \is_iterable($data)
            ? DataResponse::fromCollection($data, $serializer)
            : DataResponse::fromResource($data, $serializer);
    }

    /**
     * A meta-only {@see MetaResponse} seeded with `$meta`.
     *
     * @param array<string, mixed> $meta
     */
    public function meta(array $meta): MetaResponse
    {
        return MetaResponse::fromMeta($meta);
    }

    /**
     * A bodyless `204` {@see NoContentResponse}.
     */
    public function noContent(): NoContentResponse
    {
        return NoContentResponse::create();
    }

    /**
     * A `202 Accepted` {@see AcceptedResponse} for an action that queues its work
     * rather than completing it inline: `$contentLocation` is the URL of the job
     * resource the client polls. Pass `$job` to render it as the `202` body's `data`
     * (through the action's `outputType` serializer); omit it for a meta-only status
     * document. Chain `->withRetryAfter()` / `->withMeta()` for the poll hint / status.
     */
    public function accepted(string $contentLocation, ?object $job = null): AcceptedResponse
    {
        $response = $job !== null
            ? AcceptedResponse::forResource($job, $this->serializer())
            : AcceptedResponse::fromMeta([]);

        return $response->withContentLocation($contentLocation);
    }

    /**
     * A `303 See Other` {@see SeeOtherResponse} redirecting the client to `$location` —
     * the completion half of the async lifecycle: a job-status action returns this,
     * once the queued work is done, pointing at the produced resource.
     *
     * An action declaring `SeeOther` with a media type answers with a URL instead, such as
     * a short-lived download link: a request preferring JSON:API gets a `200` document
     * carrying it as `links.location`, any other request the `303`. `$expiresAt` is when
     * that URL stops working.
     */
    public function seeOther(string $location, ?\DateTimeInterface $expiresAt = null): SeeOtherResponse
    {
        return SeeOtherResponse::to($location, $expiresAt);
    }
}
