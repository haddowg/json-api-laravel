<?php

declare(strict_types=1);

namespace Workbench\App\Singleton;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\SingletonContext;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;
use haddowg\JsonApiLaravel\Operation\SingletonIdResolver;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The caller's inbox, served at `/inbox`: the signed-in user's inbox id comes from
 * {@see InboxScenario}.
 */
#[AsJsonApiResource(operationScopes: ['update' => ['inbox:write']])]
final class InboxResource extends AbstractResource
{
    public static string $type = 'inboxes';

    public static string $uriType = 'inbox';

    public static bool $singleton = true;

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            HasMany::make('items', 'inboxItems'),
            BelongsToMany::make('labels', 'inboxLabels'),
        ];
    }

    public function resolveSingletonId(SingletonContext $context): ?string
    {
        InboxScenario::$contexts[] = $context;

        $user = $context->attribute(SingletonIdResolver::USER_ATTRIBUTE);
        if (!$user instanceof Authenticatable) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        return \is_scalar($id) ? InboxScenario::$inboxByUser[(string) $id] ?? null : null;
    }
}
