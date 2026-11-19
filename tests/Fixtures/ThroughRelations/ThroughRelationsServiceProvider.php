<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * The lead graph, served over the store named by `through_relations.store`: `eloquent`, where
 * the advertisers' global scope hides Shadow, or `memory`, where a scoping provider serves
 * the advertisers.
 *
 * Advertisers 4 Acme, 7 Globex and 9 Shadow, which the advertisers' scope hides. Campaigns,
 * keyed by UUID:
 *
 *  - spring: Acme, budget 500, starts 2026-03-02
 *  - summer: Globex, budget 150, starts 2026-06-15
 *  - autumn: Shadow, budget 300, starts 2026-09-01, archived 2026-10-01
 *  - winter: no advertiser, budget 50, starts 2026-12-01
 *
 * Templates 1 Welcome, 2 Reminder and 3 Survey, served as `tpl-<id>`. Leads:
 *
 *  - 1 Ada, spring, templates 1 and 2
 *  - 2 Grace, summer, template 2
 *  - 3 Hedy, autumn, no templates
 *  - 4 Joan, spring, template 3
 *  - 5 Katherine, no campaign, template 1
 *  - 6 Lin, winter, no templates
 *
 * @internal
 */
final class ThroughRelationsServiceProvider extends ServiceProvider
{
    public const string SPRING = '7a1c0d52-0000-4000-8000-000000000001';

    public const string SUMMER = '7a1c0d52-0000-4000-8000-000000000002';

    public const string AUTUMN = '7a1c0d52-0000-4000-8000-000000000003';

    public const string WINTER = '7a1c0d52-0000-4000-8000-000000000004';

    private const array ADVERTISERS = [4 => ['Acme', false], 7 => ['Globex', false], 9 => ['Shadow', true]];

    private const array TEMPLATES = [1 => 'Welcome', 2 => 'Reminder', 3 => 'Survey'];

    /**
     * @var array<string, array{string, int, string, ?string, ?int}>
     */
    private const array CAMPAIGNS = [
        self::SPRING => ['Spring sale', 500, '2026-03-02', null, 4],
        self::SUMMER => ['Summer launch', 150, '2026-06-15', null, 7],
        self::AUTUMN => ['Autumn', 300, '2026-09-01', '2026-10-01 00:00:00', 9],
        self::WINTER => ['Winter', 50, '2026-12-01', null, null],
    ];

    /**
     * @var list<array{int, string, ?string, list<int>}>
     */
    private const array LEADS = [
        [1, 'Ada', self::SPRING, [1, 2]],
        [2, 'Grace', self::SUMMER, [2]],
        [3, 'Hedy', self::AUTUMN, []],
        [4, 'Joan', self::SPRING, [3]],
        [5, 'Katherine', null, [1]],
        [6, 'Lin', self::WINTER, []],
    ];

    public function register(): void
    {
        JsonApi::register([ThrLeadResource::class, ThrCampaignResource::class, ThrAdvertiserResource::class, ThrTemplateResource::class]);

        if (config('through_relations.store') !== 'memory') {
            JsonApi::provider(new EloquentDataProvider([
                'thrLeads' => ThrLead::class,
                'thrCampaigns' => ThrCampaign::class,
                'thrAdvertisers' => ThrAdvertiser::class,
                'thrTemplates' => ThrTemplate::class,
            ]), priority: -128);

            return;
        }

        $advertisers = [];
        foreach (self::ADVERTISERS as $id => [$name, $hidden]) {
            $advertisers[$id] = new ThrAdvertiserRecord((string) $id, $name, $hidden);
        }
        $templates = [];
        foreach (self::TEMPLATES as $id => $name) {
            $templates[(string) $id] = new ThrTemplateRecord((string) $id, $name);
        }
        $campaigns = [];
        foreach (self::CAMPAIGNS as $id => [$name, $budget, $start, $archived, $advertiser]) {
            $owner = $advertiser !== null ? ($advertisers[$advertiser] ?? null) : null;
            $campaign = new ThrCampaignRecord($id, $name, $budget, new \DateTimeImmutable($start), $archived !== null ? new \DateTimeImmutable($archived) : null, $owner);
            if ($owner !== null) {
                $owner->campaigns[] = $campaign;
            }
            $campaigns[$id] = $campaign;
        }
        $leads = [];
        foreach (self::LEADS as [$id, $name, $campaign, $templateIds]) {
            $owner = $campaign !== null ? ($campaigns[$campaign] ?? null) : null;
            $lead = new ThrLeadRecord((string) $id, $name, $owner, \array_map(static fn(int $template): ThrTemplateRecord => $templates[(string) $template], $templateIds));
            if ($owner !== null) {
                $owner->leads[] = $lead;
            }
            $leads[(string) $id] = $lead;
        }

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        JsonApi::provider(new InMemoryDataProvider('thrLeads', $leads, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('thrCampaigns', $campaigns, identify: $identify));
        JsonApi::provider(new InMemoryDataProvider('thrTemplates', $templates, identify: $identify));
        JsonApi::provider(new ScopedThrAdvertiserProvider(\array_values($advertisers)));
    }

    /**
     * Creates and seeds the tables the Eloquent store reads.
     */
    public static function migrate(): void
    {
        Schema::create('thr_advertisers', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->boolean('hidden')->default(false);
        });
        Schema::create('thr_campaigns', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('budget');
            $table->date('start_date');
            $table->dateTime('archived_at')->nullable();
            $table->unsignedInteger('advertiser_id')->nullable();
        });
        Schema::create('thr_templates', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('thr_leads', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('campaign_id')->nullable();
        });
        Schema::create('thr_lead_template', static function (Blueprint $table): void {
            $table->unsignedInteger('lead_id');
            $table->unsignedInteger('template_id');
        });

        foreach (self::ADVERTISERS as $id => [$name, $hidden]) {
            DB::table('thr_advertisers')->insert(['id' => $id, 'name' => $name, 'hidden' => $hidden]);
        }
        foreach (self::TEMPLATES as $id => $name) {
            DB::table('thr_templates')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (self::CAMPAIGNS as $id => [$name, $budget, $start, $archived, $advertiser]) {
            DB::table('thr_campaigns')->insert(['id' => $id, 'name' => $name, 'budget' => $budget, 'start_date' => $start, 'archived_at' => $archived, 'advertiser_id' => $advertiser]);
        }
        foreach (self::LEADS as [$id, $name, $campaign, $templateIds]) {
            DB::table('thr_leads')->insert(['id' => $id, 'name' => $name, 'campaign_id' => $campaign]);
            foreach ($templateIds as $template) {
                DB::table('thr_lead_template')->insert(['lead_id' => $id, 'template_id' => $template]);
            }
        }
    }
}
