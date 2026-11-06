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
        Schema::create('site_builder_saved_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->default('General');
            $table->json('tags')->nullable();
            $table->string('visibility')->default('private');
            $table->string('owner_id', 191);
            $table->string('owner_type');
            $table->unsignedBigInteger('write_version')->default(0);
            $table->unsignedBigInteger('current_version')->default(0);
            foreach (['created_by', 'updated_by'] as $actor) {
                $table->string($actor, 191);
                $table->string($actor.'_type');
            }
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });
        Schema::create('site_builder_saved_section_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('section_id')->constrained('site_builder_saved_sections')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->json('document');
            $table->string('digest', 64);
            $table->json('metadata');
            $table->string('source_kind', 32);
            $table->string('source_id', 191);
            $table->string('source_version', 191);
            $table->string('source_digest', 64);
            $table->json('source_root_ids');
            $table->string('created_by', 191);
            $table->string('created_by_type');
            $table->timestamp('created_at');
            $table->unique(['section_id', 'version']);
        });
    }

    public function down(): void
    {
        if (DB::table('site_builder_saved_sections')->exists() || DB::table('site_builder_saved_section_versions')->exists()) {
            throw new RuntimeException('Saved-section history requires a reviewed backup/conversion before downgrade. No reusable content was removed.');
        }
        Schema::drop('site_builder_saved_section_versions');
        Schema::drop('site_builder_saved_sections');
    }
};
