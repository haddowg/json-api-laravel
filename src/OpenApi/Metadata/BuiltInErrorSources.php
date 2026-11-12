<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\OpenApi\Metadata;

use haddowg\JsonApi\Exception\ClassListErrorSource;
use haddowg\JsonApi\Exception\ErrorCatalogSourceInterface;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\Exception\ScopedErrorSource;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApiLaravel\Atomic\AtomicHrefInvalid;
use haddowg\JsonApiLaravel\Atomic\AtomicHrefUnresolvable;
use haddowg\JsonApiLaravel\Atomic\AtomicOperationNotExposed;
use haddowg\JsonApiLaravel\Atomic\AtomicOperationsNotSupported;
use haddowg\JsonApiLaravel\Atomic\AtomicOperationsUnavailable;
use haddowg\JsonApiLaravel\Atomic\AtomicTargetTypeUnknown;
use haddowg\JsonApiLaravel\DataPersister\AsyncWriteNotAllowedInAtomicOperation;
use haddowg\JsonApiLaravel\Validation\ValidationFailed;

/**
 * The package's own described errors, which every server catalogues ahead of the
 * application's. The Symfony bundle catalogues the same codes in the same order, so
 * the two adapters project identical documents.
 *
 * `VALIDATION_FAILED` is scoped per server: the writes that read a document, the batch,
 * and the actions of this server that take a document. An action reading no document
 * never validates one.
 */
final class BuiltInErrorSources
{
    /**
     * @param list<TypeMetadataInterface> $types the server's types
     *
     * @return list<ErrorCatalogSourceInterface>
     */
    public static function for(array $types): array
    {
        return [
            new ClassListErrorSource(
                AsyncWriteNotAllowedInAtomicOperation::class,
                AtomicHrefInvalid::class,
                AtomicHrefUnresolvable::class,
                AtomicOperationNotExposed::class,
                AtomicOperationsNotSupported::class,
                AtomicOperationsUnavailable::class,
                AtomicTargetTypeUnknown::class,
                ValidationFailed::class,
            ),
            new ScopedErrorSource(self::validationScope($types), ValidationFailed::class),
        ];
    }

    /**
     * @param list<TypeMetadataInterface> $types
     */
    private static function validationScope(array $types): ErrorScope
    {
        $scope = ErrorScope::kinds(
            OperationKind::Create,
            OperationKind::Update,
            OperationKind::UpdateRelationship,
            OperationKind::AddRelationship,
            OperationKind::RemoveRelationship,
            OperationKind::Atomic,
        );

        foreach ($types as $type) {
            $operations = [];
            foreach ($type->actions() as $action) {
                if ($action->inputMode() !== ActionInputMode::Document) {
                    continue;
                }
                foreach ($action->methods() as $method) {
                    $operations[] = 'action.' . $action->path() . '.' . \strtolower($method);
                }
            }
            if ($operations !== []) {
                $scope = $scope->and(ErrorScope::on($type->type(), ...$operations));
            }
        }

        return $scope;
    }
}
