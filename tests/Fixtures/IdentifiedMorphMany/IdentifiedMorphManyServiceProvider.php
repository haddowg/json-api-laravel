<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorphMany;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires `users`, whose `companies` MorphToMany is identified by join rows, over the store named
 * by `mtm.store`: `eloquent` or `memory`. With `mtm.hides`, agency 2 is hidden by the
 * {@see HidesHiddenAgencies} global scope on Eloquent, or by {@see VisibleAgenciesOnly} in memory.
 *
 * The seed is the Symfony bundle's twin suite's, so both render the same documents: agencies 1
 * Agency One, 2 Hidden Agency and 3 Agency Three; advertisers 1 and 2; publisher 1; and users 1
 * Ada holding six companies, 2 Bob holding one, 3 Cy holding two and 4 Di holding none.
 *
 * @internal
 */
final class IdentifiedMorphManyServiceProvider extends ServiceProvider
{
    /**
     * The seeded users: name and companies as `type:id`, in stored order.
     */
    public const array USERS = [
        1 => ['Ada', ['agencies:1', 'advertisers:1', 'publishers:1', 'agencies:2', 'advertisers:2', 'agencies:3']],
        2 => ['Bob', ['advertisers:2']],
        3 => ['Cy', ['agencies:1', 'publishers:1']],
        4 => ['Di', []],
    ];

    /**
     * Each company type's rows: id, name, code and whether it is hidden.
     */
    public const array COMPANIES = [
        'agencies' => [[1, 'Agency One', 'AG1', false], [2, 'Hidden Agency', 'AG2', true], [3, 'Agency Three', 'AG3', false]],
        'advertisers' => [[1, 'Advertiser One', 'AD1', false], [2, 'Advertiser Two', 'AD2', false]],
        'publishers' => [[1, 'Publisher One', 'PU1', false]],
    ];

    public function register(): void
    {
        JsonApi::register([UserResource::class, AgencyResource::class, AdvertiserResource::class, PublisherResource::class]);

        $hides = (bool) config('mtm.hides');

        if (config('mtm.store') === 'memory') {
            $identify = static fn(object $row): string => $row instanceof CompanyRecord || $row instanceof UserRecord ? $row->id : '';
            $companies = [];
            foreach (self::COMPANIES as $type => $rows) {
                foreach ($rows as [$id, $name, $code, $hidden]) {
                    $companies[$type][(string) $id] = new CompanyRecord((string) $id, $name, $code, $hidden);
                }
            }

            $users = [];
            foreach (self::USERS as $id => [$name, $keys]) {
                $users[(string) $id] = new UserRecord((string) $id, $name, \array_map(static function (string $key): array {
                    [$type, $companyId] = \explode(':', $key);

                    return [$type, (int) $companyId];
                }, $keys));
            }

            $userProvider = new InMemoryDataProvider('users', $users, identify: $identify);
            $this->app->instance('mtm.users', $userProvider);
            JsonApi::provider($userProvider);
            JsonApi::persister(new InMemoryDataPersister('users', $userProvider->store(), static fn(): UserRecord => new UserRecord()));
            JsonApi::provider($hides ? new VisibleAgenciesOnly($companies['agencies']) : new InMemoryDataProvider('agencies', $companies['agencies'], identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('advertisers', $companies['advertisers'], identify: $identify));
            JsonApi::provider(new InMemoryDataProvider('publishers', $companies['publishers'], identify: $identify));

            return;
        }

        $models = [
            'users' => MtmUser::class,
            'agencies' => $hides ? MtmHiddenAgency::class : MtmAgency::class,
            'advertisers' => MtmAdvertiser::class,
            'publishers' => MtmPublisher::class,
        ];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }
}
