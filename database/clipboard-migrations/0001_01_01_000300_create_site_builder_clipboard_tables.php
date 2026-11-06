<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_builder_clipboard_owners', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('actor_id', 191);
            $table->string('actor_type');
            $table->string('namespace', 64);
            $table->unsignedBigInteger('sequence')->default(0);
            $table->timestamps();
        });
        Schema::create('site_builder_clipboard_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_id', 64);
            $table->foreign('owner_id')->references('id')->on('site_builder_clipboard_owners')->cascadeOnDelete();
            $table->foreignId('source_page_id')->nullable()->constrained('site_builder_pages')->nullOnDelete();
            $table->unsignedBigInteger('source_version');
            $table->unsignedBigInteger('owner_sequence');
            $table->string('source_domain', 191);
            $table->string('source_layout')->nullable();
            $table->uuid('source_tab_id');
            $table->longText('document');
            $table->longText('envelope');
            $table->string('digest', 64);
            $table->string('envelope_digest', 64);
            $table->string('copy_history_digest', 64)->nullable();
            $table->unsignedBigInteger('storage_bytes');
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
            $table->index(['owner_id', 'owner_sequence']);
        });
    }

    public function down(): void
    {
        // Operational clipboard state is disposable; authored drafts are untouched.
        Schema::dropIfExists('site_builder_clipboard_snapshots');
        Schema::dropIfExists('site_builder_clipboard_owners');
    }
};
