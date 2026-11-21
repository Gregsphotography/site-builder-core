<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('site_builder_pages', 'draft_settings')) {
            Schema::table('site_builder_pages', function (Blueprint $table): void {
                $table->json('draft_settings')->nullable();
            });
        }
        if (! Schema::hasColumn('site_builder_page_revisions', 'settings_snapshot')) {
            Schema::table('site_builder_page_revisions', function (Blueprint $table): void {
                $table->json('settings_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        $hasDraft = Schema::hasColumn('site_builder_pages', 'draft_settings');
        $hasHistory = Schema::hasColumn('site_builder_page_revisions', 'settings_snapshot');
        if (DB::table('site_builder_pages')->where('status', 'trashed')->exists()
            || ($hasDraft && DB::table('site_builder_pages')->whereNotNull('draft_settings')->exists())
            || ($hasHistory && DB::table('site_builder_page_revisions')->whereNotNull('settings_snapshot')->exists())) {
            throw new RuntimeException('Trashed pages, staged settings and publication settings history require a native backup or a reviewed conversion before downgrading.');
        }
        if ($hasHistory) {
            Schema::table('site_builder_page_revisions', function (Blueprint $table): void {
                $table->dropColumn('settings_snapshot');
            });
        }
        if ($hasDraft) {
            Schema::table('site_builder_pages', function (Blueprint $table): void {
                $table->dropColumn('draft_settings');
            });
        }
    }
};
