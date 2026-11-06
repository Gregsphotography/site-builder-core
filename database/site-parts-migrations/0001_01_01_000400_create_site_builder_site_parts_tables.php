<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_builder_site_parts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 16);
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('draft_payload');
            $table->unsignedBigInteger('write_version')->default(0);
            $table->uuid('current_revision_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('created_by', 191);
            $table->string('created_by_type');
            $table->string('updated_by', 191);
            $table->string('updated_by_type');
            $table->timestamps();
        });
        Schema::create('site_builder_site_part_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('resource_id');
            $table->foreign('resource_id')->references('id')->on('site_builder_site_parts')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('kind', 16);
            $table->longText('payload');
            $table->char('digest', 64);
            $table->unsignedBigInteger('published_from_version');
            $table->string('created_by', 191);
            $table->string('created_by_type');
            $table->string('label')->nullable();
            $table->timestamp('published_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->string('withdrawn_by', 191)->nullable();
            $table->string('withdrawn_by_type')->nullable();
            $table->unique(['resource_id', 'sequence']);
        });
        Schema::table('site_builder_site_parts', function (Blueprint $table): void {
            $table->foreign('current_revision_id')->references('id')->on('site_builder_site_part_revisions')->restrictOnDelete();
        });
        Schema::create('site_builder_site_part_locks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('resource_id')->unique();
            $table->foreign('resource_id')->references('id')->on('site_builder_site_parts')->cascadeOnDelete();
            $table->string('actor_id', 191);
            $table->string('actor_type');
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('site_builder_content_dependencies', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->string('owner_kind', 32);
            $table->string('owner_id', 191);
            $table->string('scope', 32);
            $table->string('owner_version', 191);
            $table->char('owner_digest', 64);
            $table->text('source_path');
            $table->uuid('resource_id');
            $table->uuid('revision_id');
            $table->char('digest', 64);
            $table->foreign('resource_id')->references('id')->on('site_builder_site_parts')->restrictOnDelete();
            $table->foreign('revision_id')->references('id')->on('site_builder_site_part_revisions')->restrictOnDelete();
            $table->index(['owner_kind', 'owner_id', 'scope'], 'site_builder_dependency_owner');
        });
        // K migrations are immutable. Extend the installed optional store separately.
        if (Schema::hasTable('site_builder_draft_histories') && ! Schema::hasColumn('site_builder_draft_histories', 'site_part_id')) {
            Schema::table('site_builder_draft_histories', function (Blueprint $table): void {
                $table->unsignedBigInteger('page_id')->nullable()->change();
                $table->uuid('site_part_id')->nullable();
                $table->foreign('site_part_id')->references('id')->on('site_builder_site_parts')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('site_builder_site_parts')->exists() || DB::table('site_builder_site_part_revisions')->exists() || DB::table('site_builder_content_dependencies')->exists()) {
            throw new RuntimeException('Site-part downgrade would discard richer content or immutable dependencies. Restore a compatible backup or perform a reviewed conversion first.');
        }
        if (Schema::hasTable('site_builder_draft_histories') && Schema::hasColumn('site_builder_draft_histories', 'site_part_id')) {
            Schema::table('site_builder_draft_histories', function (Blueprint $table): void {
                $table->dropForeign(['site_part_id']);
                $table->dropColumn('site_part_id');
                $table->unsignedBigInteger('page_id')->nullable(false)->change();
            });
        }
        Schema::drop('site_builder_content_dependencies');
        Schema::drop('site_builder_site_part_locks');
        Schema::table('site_builder_site_parts', fn (Blueprint $table) => $table->dropForeign(['current_revision_id']));
        Schema::drop('site_builder_site_part_revisions');
        Schema::drop('site_builder_site_parts');
    }
};
