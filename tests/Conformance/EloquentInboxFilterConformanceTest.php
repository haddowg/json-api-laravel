<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use haddowg\JsonApiLaravel\Tests\Fixtures\Inbox\InboxServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see InboxFilterConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite, where a morph filter reads the model's own morphTo relation through the
 * morph map.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentInboxFilterConformanceTest extends InboxFilterConformanceTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aClosureIdentifiedMorphToFiltersThroughTheModelsMorphTo(): void
    {
        self::assertSame(['1', '2'], $this->ids('/api/inbNativeItems?filter[subject][type]=inbRfps'));
        self::assertSame(['3'], $this->ids('/api/inbNativeItems?filter[subject][type]=inbCampaigns&filter[subject][id]=cmp-1'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function aMorphToComparesTheMorphColumnsAndAMorphToManyIsOneExists(): void
    {
        $subject = $this->itemSelect('/api/inbItems?filter[subject][type]=inbRfps&filter[subject][id]=812,813');
        self::assertStringContainsString('"inb_items"."subject_type" in (?)', $subject);
        self::assertStringContainsString('"inb_items"."subject_id" in (?, ?)', $subject);
        self::assertStringNotContainsString('exists', $subject);

        $linked = $this->itemSelect('/api/inbItems?filter[linked][type]=inbRfps&filter[linked][id]=812,813');
        self::assertSame(1, \substr_count($linked, 'exists'));
        self::assertStringContainsString('"inb_links"."linked_id" in (?, ?)', $linked);
    }

    protected function store(): string
    {
        return 'eloquent';
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('inb_rfps', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('inb_campaigns', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('inb_folders', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('inb_items', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('folder_id');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->dateTime('read_ts')->nullable();
            $table->dateTime('archived_ts')->nullable();
        });
        Schema::create('inb_links', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('item_id');
            $table->string('linked_type');
            $table->string('linked_id');
        });

        foreach (InboxServiceProvider::rfps() as $id => $name) {
            DB::table('inb_rfps')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (InboxServiceProvider::campaigns() as $id => $name) {
            DB::table('inb_campaigns')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (InboxServiceProvider::folders() as $id => $name) {
            DB::table('inb_folders')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (InboxServiceProvider::items() as $row) {
            DB::table('inb_items')->insert([
                'id' => $row['id'],
                'title' => $row['title'],
                'folder_id' => $row['folder'],
                'subject_type' => $row['subject_type'],
                'subject_id' => $row['subject_id'],
                'read_ts' => $row['read'] ? InboxServiceProvider::AT : null,
                'archived_ts' => $row['archived'] ? InboxServiceProvider::AT : null,
            ]);
            foreach ($row['links'] as [$type, $id]) {
                DB::table('inb_links')->insert(['item_id' => $row['id'], 'linked_type' => $type, 'linked_id' => $id]);
            }
        }
    }

    /**
     * The one query that selects the items `$path` answers with, lower-cased.
     */
    private function itemSelect(string $path): string
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->request($path)->assertOk();
        DB::disableQueryLog();

        $selects = [];
        foreach (DB::getQueryLog() as $entry) {
            $sql = \strtolower($entry['query']);
            if (\str_starts_with($sql, 'select "inb_items".*') || \str_starts_with($sql, 'select * from "inb_items"')) {
                $selects[] = $sql;
            }
        }

        self::assertCount(1, $selects, 'one query selects the items');

        return $selects[0];
    }
}
