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
        Schema::create('site_builder_content_index_lock', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('site_builder_content_index_lock')->insert(['id' => 1]);
        Schema::create('site_builder_content_index', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('scope', 120);
            $table->string('source', 80);
            $table->string('item_id', 255);
            $table->string('locale', 32);
            $table->string('fingerprint', 64);
            $table->string('type', 255);
            $table->text('title');
            $table->text('summary');
            $table->longText('search_text');
            $table->string('date', 32)->nullable();
            $table->boolean('listable');
            $table->boolean('searchable');
            $table->index(['scope', 'locale', 'source'], 'sb_content_scope');
            $table->index(['scope', 'locale', 'type'], 'sb_content_type');
        });
        Schema::create('site_builder_content_tags', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('content_id', 64)->index();
            $table->string('tag', 80)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_builder_content_tags');
        Schema::dropIfExists('site_builder_content_index');
        Schema::dropIfExists('site_builder_content_index_lock');
    }
};
