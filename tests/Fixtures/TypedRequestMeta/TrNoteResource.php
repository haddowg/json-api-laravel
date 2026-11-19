<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\RelationshipMetaContext;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApiLaravel\Hook\HookContext;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiLaravel\Hook\ResourceLifecycleHooksTrait;

/**
 * Notes declare the meta each write takes, their resource objects' meta both ways, and
 * the meta-only document adding items answers with.
 */
final class TrNoteResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'tr-notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            Str::make('createdWith')->storedAs('created_with')->readOnly()->nullable(),
            BelongsToMany::make('items', 'tr-items'),
            BelongsTo::make('lead', 'tr-items')->nullable()->identifierMeta(
                static fn(mixed $note, mixed $item, JsonApiRequestInterface $request): array => $request->hasHeader('X-Bogus-Meta') ? ['role' => 'owner', 'bogus' => true] : ['role' => 'owner'],
                fields: [Str::make('role')],
            ),
        ];
    }

    public function requestDocumentMeta(RequestMetaContext $context): iterable
    {
        return match (true) {
            $context->is(OperationType::Create) && $context->isAtomic() => [Str::make('idempotencyKey')->required(), Str::make('batchTag')->required()],
            $context->is(OperationType::Create) => [Str::make('idempotencyKey')->required()->example('k-1'), DateTime::make('scheduledAt'), Integer::make('attempt')],
            $context->is(OperationType::Update) => [Str::make('reason')],
            $context->is(OperationType::Delete) => [Str::make('reason')->required()],
            $context->isRelationship('items') => [Str::make('reason')],
            default => [],
        };
    }

    public function resourceMeta(MetaContext $context): iterable
    {
        return [
            // A note titled "Broken" renders a revision of the wrong type.
            Integer::make('revision')->readOnly()->extractUsing(static fn(object $note): mixed => Accessor::get($note, 'title') === 'Broken' ? 'many' : Accessor::get($note, 'revision')),
            Str::make('source')->writeOnly()->fillUsing(static function (object $note, mixed $value): object {
                Accessor::set($note, 'source', \is_string($value) ? $value : null);

                return $note;
            }),
            Str::make('label')->writeOnly(),
        ];
    }

    public function relationshipDocumentMeta(RelationshipMetaContext $context): iterable
    {
        return $context->isRelationship('items', Mode::Add)
            ? [Integer::make('count')->required()->example(2)->extractUsing(static fn(object $note): int => $note instanceof Note ? $note->items()->count() : \count(\is_array($items = Accessor::get($note, 'items')) ? $items : []))]
            : [];
    }

    public function beforeCreate(object $entity, HookContext $context): void
    {
        $key = $context->writtenMeta()->document('idempotencyKey');
        Accessor::set($entity, 'created_with', \is_string($key) ? $key : null);
    }
}
