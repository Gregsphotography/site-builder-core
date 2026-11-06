<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Installation\MigrationPublisher;
use SiteBuilder\Core\SiteParts\SitePartCommands;
use SiteBuilder\Core\SiteParts\SitePartDraftHistory;
use SiteBuilder\Core\SiteParts\SitePartLocks;
use SiteBuilder\Core\SiteParts\SitePartPayload;
use SiteBuilder\Core\Tests\DatabaseTestCase;

class SitePartInstallationTest extends DatabaseTestCase
{
    private function migrateDirectory(string $directory): void
    {
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/'.$directory), '--realpath' => true, '--force' => true])->assertExitCode(0);
    }

    public function test_parts_first_then_history_adds_the_typed_owner_seam_and_can_open_a_real_resource_journal(): void
    {
        $this->migrateDirectory('site-parts-migrations');
        $this->assertFalse(Schema::hasTable('site_builder_draft_histories'));
        $this->migrateDirectory('history-migrations');
        $this->assertTrue(Schema::hasColumn('site_builder_draft_histories', 'site_part_id'));
        Gate::before(fn () => true);
        $actor = new GenericUser(['id' => (string) Str::ulid()]);
        $part = app(SitePartCommands::class)->create($actor, 'section', ['name' => 'Ordered install'], SitePartPayload::document('section', new Document))->part;
        $lease = app(SitePartLocks::class)->acquire($actor, $part->id);
        $session = app(SitePartDraftHistory::class)->open($actor, $part->id, $lease->token, (string) Str::uuid());
        $this->assertSame(0, $session->status['undoCount']);
    }

    public function test_history_first_then_parts_extends_without_republishing_or_rewriting_its_original_migration(): void
    {
        $file = __DIR__.'/../../database/history-migrations/0001_01_01_000200_create_site_builder_draft_histories_table.php';
        $before = hash_file('sha256', $file);
        $this->migrateDirectory('history-migrations');
        $this->assertFalse(Schema::hasColumn('site_builder_draft_histories', 'site_part_id'));
        $this->migrateDirectory('site-parts-migrations');
        $this->assertTrue(Schema::hasColumn('site_builder_draft_histories', 'site_part_id'));
        $this->assertSame($before, hash_file('sha256', $file));
    }

    public function test_all_optional_migration_publications_with_one_frozen_timestamp_boot_a_fresh_database(): void
    {
        Schema::dropAllTables();
        $directory = sys_get_temp_dir().'/site-builder-all-migrations-'.Str::uuid();
        $publisher = app(MigrationPublisher::class);
        $timestamp = new DateTimeImmutable('2026-11-06 12:00:00 UTC');
        $files = [];
        try {
            foreach (['migrations', 'history-migrations', 'saved-sections-migrations', 'clipboard-migrations', 'site-parts-migrations'] as $source) {
                $files = [...$files, ...$publisher->publish(realpath(__DIR__.'/../../database/'.$source), $directory, $timestamp)];
            }
            $this->assertCount(count($files), array_unique(array_map(fn ($path) => substr(basename($path), 0, 17), $files)));
            $this->artisan('migrate', ['--path' => $directory, '--realpath' => true, '--force' => true])->assertExitCode(0);
            foreach (['site_builder_pages', 'site_builder_draft_histories', 'site_builder_saved_sections', 'site_builder_clipboard_snapshots', 'site_builder_site_parts', 'site_builder_site_part_revisions', 'site_builder_content_dependencies'] as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }
            $this->assertTrue(Schema::hasColumn('site_builder_draft_histories', 'site_part_id'));
            $this->assertSame([], $publisher->publish(realpath(__DIR__.'/../../database/history-migrations'), $directory, $timestamp));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
