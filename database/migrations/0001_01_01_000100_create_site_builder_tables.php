<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_builder_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('type')->default('page')->index();
            $table->string('status')->default('draft')->index();
            $table->string('layout')->nullable();
            foreach (['draft_content', 'published_content', 'seo', 'schema', 'data'] as $column) {
                $table->json($column)->nullable();
            }
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('write_version')->default(0);
            $table->unsignedBigInteger('published_from_version')->nullable();
            $table->timestamps();
        });
        Schema::create('site_builder_page_slugs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('site_builder_pages')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('slug');
            $table->timestamps();
            $table->unique(['locale', 'slug']);
            $table->unique(['page_id', 'locale']);
        });
        Schema::create('site_builder_page_redirects', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 12);
            $table->string('from_slug');
            $table->foreignId('page_id')->constrained('site_builder_pages')->cascadeOnDelete();
            $table->unsignedSmallInteger('status')->default(301);
            $table->timestamps();
            $table->unique(['locale', 'from_slug']);
        });
        Schema::create('site_builder_page_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('site_builder_pages')->cascadeOnDelete();
            $table->string('scope')->default('content');
            $table->json('content')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('label')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('published_from_version')->nullable();
            $table->string('origin')->default('native');
            $table->index(['page_id', 'id']);
        });
        Schema::create('site_builder_page_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->unique()->constrained('site_builder_pages')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('site_builder_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('namespace')->unique();
            $table->json('data')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('site_builder_menus', function (Blueprint $table): void {
            $table->id();
            $table->string('location')->unique();
            $table->json('items')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('site_builder_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->string('source_fingerprint', 64);
            $table->unsignedInteger('format_version');
            $table->unsignedInteger('mapper_version');
            $table->string('status');
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
        Schema::create('site_builder_import_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('site_builder_import_runs')->restrictOnDelete();
            $table->string('source', 64);
            $table->string('kind', 32);
            $table->string('source_id', 191);
            $table->string('target_id', 191);
            $table->string('source_digest', 64);
            $table->string('target_digest', 64);
            $table->unsignedBigInteger('imported_write_version')->nullable();
            $table->unique(['source', 'kind', 'source_id'], 'site_builder_import_source_unique');
        });
    }

    public function down(): void
    {
        foreach (['import_map', 'import_runs', 'menus', 'settings', 'page_locks', 'page_revisions', 'page_redirects', 'page_slugs', 'pages'] as $table) {
            Schema::dropIfExists('site_builder_'.$table);
        }
    }
};
