<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresAcceptedBodies;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionExample;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLink;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApiLaravel\Action\ActionDescriptor;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

/**
 * Adapts a package {@see ActionDescriptor} (a resolved `#[AsJsonApiAction]`) to the
 * OpenAPI {@see ActionMetadataInterface} core's projector consumes — the Laravel twin of
 * the bundle's `OpenApi\Metadata\ActionMetadata`.
 *
 * The package and core each carry their own scope/input enums (same case names, different
 * namespaces — the package's drive routing/dispatch, core's drive projection), so they are
 * mapped **by case name**.
 *
 * `isSecured()` reads whether the descriptor declares an `ability` (the ability itself is
 * never surfaced). `responds()` rehydrates the descriptor's cached `{kind, ref}` scalar
 * pairs into core's atomic response objects — the concrete classes the projector
 * discriminates on. `tags` is already resolved by the {@see ActionRegistry} (empty action
 * tags fall back to the mount type's default tag) so the projected document matches the
 * bundle's for an identical action.
 */
final readonly class ActionMetadata implements DeclaresActionLink, DeclaresAcceptedBodies, DeclaresActionExample
{
    /**
     * @param list<string> $tags the OpenAPI tag names (already resolved against the mount type's default)
     */
    public function __construct(
        private ActionDescriptor $descriptor,
        private array $tags,
    ) {}

    public function path(): string
    {
        return $this->descriptor->path;
    }

    public function methods(): array
    {
        return $this->descriptor->methods;
    }

    public function scope(): ActionScope
    {
        return ActionScope::{$this->descriptor->scope->name};
    }

    public function inputMode(): ActionInputMode
    {
        return ActionInputMode::{$this->descriptor->input->name};
    }

    public function inputType(): ?string
    {
        // The contract carries the input type only when a JSON:API document is read: in
        // Document mode, or through an `accepts` entry read as one. The descriptor stores the
        // mount-type default for every mode, so it is surfaced only then.
        return $this->inputMode() === ActionInputMode::Document || \in_array('Document', $this->descriptor->accepts, true)
            ? $this->descriptor->inputType
            : null;
    }

    /**
     * @return non-empty-list<ActionResponse>
     */
    public function responds(): array
    {
        // A raw result carries a schema, headers and an example the discovery snapshot does
        // not keep, so an action declaring one reads its declaration off the handler.
        if (\in_array('raw', \array_column($this->descriptor->responds, 'kind'), true)) {
            foreach ((new \ReflectionClass($this->descriptor->handlerClass))->getAttributes(AsJsonApiAction::class) as $attribute) {
                return $attribute->newInstance()->responds;
            }
        }

        $responds = [];
        foreach ($this->descriptor->responds as $response) {
            $responds[] = match ($response['kind']) {
                'resource' => new ActionResource((string) $response['ref']),
                'accepted' => new Accepted((string) $response['ref']),
                'meta' => new MetaResult(),
                'nocontent' => new NoContent(),
                'seeother' => new SeeOther(),
                default => throw new \LogicException(\sprintf(
                    'Unknown action response kind "%s" for action "%s" on type "%s".',
                    $response['kind'],
                    $this->descriptor->path,
                    $this->descriptor->type,
                )),
            };
        }

        return $responds;
    }

    public function isSecured(): bool
    {
        return $this->descriptor->ability !== null;
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function summary(): ?string
    {
        return $this->descriptor->summary;
    }

    public function description(): ?string
    {
        return $this->descriptor->description;
    }

    /**
     * The action's accepted request bodies, read off the handler's attribute, since a body's
     * schema is not kept in the discovery snapshot. Empty for an action without `accepts`.
     */
    public function accepts(): array
    {
        if ($this->descriptor->accepts === []) {
            return [];
        }

        foreach ((new \ReflectionClass($this->descriptor->handlerClass))->getAttributes(AsJsonApiAction::class) as $attribute) {
            return $attribute->newInstance()->acceptedBodies();
        }

        return [];
    }

    /**
     * The `example` the handler's attribute declares, read off the attribute because the
     * discovery snapshot keeps scalars only. Null for a synthesized action with none.
     */
    public function example(): OperationExample|false|null
    {
        if (!\class_exists($this->descriptor->handlerClass)) {
            return null;
        }

        foreach ((new \ReflectionClass($this->descriptor->handlerClass))->getAttributes(AsJsonApiAction::class) as $attribute) {
            return $attribute->newInstance()->example;
        }

        return null;
    }

    /**
     * The action's path, the `links` member an `asLink` action renders as; null for any
     * other action.
     */
    public function linkName(): ?string
    {
        return $this->descriptor->asLink ? $this->descriptor->path : null;
    }
}
