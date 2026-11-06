<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_builder_site_parts') && Schema::hasTable('site_builder_draft_histories') && ! Schema::hasColumn('site_builder_draft_histories', 'site_part_id')) {
            Schema::table('site_builder_draft_histories', function (Blueprint $table): void {
                $table->unsignedBigInteger('page_id')->nullable()->change();
                $table->uuid('site_part_id')->nullable();
                $table->foreign('site_part_id')->references('id')->on('site_builder_site_parts')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('site_builder_site_parts') && Schema::hasColumn('site_builder_draft_histories', 'site_part_id')) {
            throw new RuntimeException('Typed site-part history is still required by the installed site-part schema. Perform its reviewed downgrade first; no journal or content was removed.');
        }
    }
};
