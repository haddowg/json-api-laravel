<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionOutputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApiLaravel\Action\ActionDescriptor;

/**
 * Adapts a package {@see ActionDescriptor} (a resolved `#[AsJsonApiAction]`) to the
 * OpenAPI {@see ActionMetadataInterface} core's projector consumes — the Laravel twin of
 * the bundle's `OpenApi\Metadata\ActionMetadata`.
 *
 * The package and core each carry their own scope/input/output enums (same case names,
 * different namespaces — the package's drive routing/dispatch, core's drive projection),
 * so they are mapped **by case name**.
 *
 * `isSecured()` reads whether the descriptor declares an `ability` (the ability itself is
 * never surfaced). `outputMode()` maps the descriptor's resolved
 * {@see \haddowg\JsonApiLaravel\Action\ActionOutput} to core's {@see ActionOutputMode} by
 * case name. `outputType()` maps the empty-string sentinel (a `returns204`/`outputMeta`
 * action carries no response resource) to `null`, and is read only in
 * {@see ActionOutputMode::Document}. `tags` is already resolved by the {@see ActionRegistry}
 * (empty action tags fall back to the mount type's default tag) so the projected document
 * matches the bundle's for an identical action.
 */
final readonly class ActionMetadata implements ActionMetadataInterface
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
        // The contract carries the input type only in Document mode (None/Raw read no
        // JSON:API request schema); the descriptor stores the mount-type default for
        // every mode, so it is surfaced only when the input is a Document.
        return $this->inputMode() === ActionInputMode::Document ? $this->descriptor->inputType : null;
    }

    public function outputMode(): ActionOutputMode
    {
        return ActionOutputMode::{$this->descriptor->output->name};
    }

    public function outputType(): ?string
    {
        return $this->descriptor->outputType !== '' ? $this->descriptor->outputType : null;
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
        return null;
    }

    public function description(): ?string
    {
        return null;
    }
}
