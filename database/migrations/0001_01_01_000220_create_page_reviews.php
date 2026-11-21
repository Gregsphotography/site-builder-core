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
        Schema::create('site_builder_page_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('page_id')->constrained('site_builder_pages')->cascadeOnDelete();
            $table->unsignedBigInteger('draft_version');
            $table->json('content')->nullable();
            $table->json('settings');
            $table->string('status', 24);
            $table->string('requested_by', 191);
            $table->string('requested_by_type');
            $table->unsignedBigInteger('expires_at');
            $table->string('reviewer')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('scheduled_for')->nullable();
            $table->string('publisher', 191)->nullable();
            $table->string('publisher_type')->nullable();
            $table->string('publisher_guard')->nullable();
            $table->string('failure')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_for']);
            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('site_builder_page_reviews')->exists()) {
            throw new RuntimeException('Preserve client sign-offs and publication schedules before removing review storage.');
        }
        Schema::drop('site_builder_page_reviews');
    }
};
