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
        if (! Schema::hasTable('site_builder_route_locks')) {
            Schema::create('site_builder_route_locks', function (Blueprint $table): void {
                $table->string('name', 20)->primary();
            });
        }
        DB::table('site_builder_route_locks')->insertOrIgnore([['name' => 'redirects'], ['name' => 'missing-paths']]);
        if (! Schema::hasTable('site_builder_missing_paths')) {
            Schema::create('site_builder_missing_paths', function (Blueprint $table): void {
                $table->id();
                $table->string('locale', 12);
                $table->string('slug');
                $table->unsignedInteger('hits')->default(1);
                $table->timestamp('last_seen_at')->index();
                $table->unique(['locale', 'slug']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_builder_missing_paths');
        Schema::dropIfExists('site_builder_route_locks');
    }
};
