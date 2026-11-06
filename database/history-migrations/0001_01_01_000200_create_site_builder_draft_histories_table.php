<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_builder_draft_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Owner coordinates are typed for a later separately authorized resource adapter.
            $table->string('owner_kind', 32);
            $table->string('owner_id', 191);
            $table->foreignId('page_id')->constrained('site_builder_pages')->cascadeOnDelete();
            $table->uuid('tab_id');
            $table->string('actor_id', 191);
            $table->string('actor_type', 255);
            $table->char('lease_hash', 64);
            $table->uuid('epoch');
            $table->unsignedBigInteger('sequence')->default(0);
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedBigInteger('accepted_version');
            $table->char('accepted_digest', 64);
            $table->longText('checkpoints');
            $table->text('actions');
            $table->text('group_state')->nullable();
            $table->unsignedBigInteger('evicted')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['owner_kind', 'owner_id', 'tab_id'], 'site_builder_history_owner_tab_unique');
        });
    }

    public function down(): void
    {
        // History is transient; dropping it never rolls back accepted page content.
        Schema::dropIfExists('site_builder_draft_histories');
    }
};
