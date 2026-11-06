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
        Schema::table('site_builder_page_locks', function (Blueprint $table): void {
            $table->string('user_id', 191)->change();
            $table->string('user_type')->nullable();
        });
        Schema::table('site_builder_page_revisions', function (Blueprint $table): void {
            $table->string('created_by', 191)->nullable()->change();
            $table->string('created_by_type')->nullable();
        });
    }

    public function down(): void
    {
        // Old code cannot faithfully represent opaque identities. Refuse the downgrade
        // before any DDL rather than truncate authorship or hand a lease to another actor.
        $maximum = DB::getDriverName() === 'sqlite' ? '9223372036854775807' : '18446744073709551615';
        foreach (['site_builder_page_locks' => ['user_id', 'user_type'], 'site_builder_page_revisions' => ['created_by', 'created_by_type']] as $table => [$id, $type]) {
            foreach (DB::table($table)->select([$id, $type])->cursor() as $row) {
                $value = (string) $row->{$id};
                if ($row->{$type} !== null || ($row->{$id} !== null && (! preg_match('/^(0|[1-9][0-9]{0,19})$/D', $value) || strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)))) {
                    throw new RuntimeException('Opaque actor identities require a reviewed data migration before downgrading core. No actor data was changed.');
                }
            }
        }
        Schema::table('site_builder_page_locks', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->change();
            $table->dropColumn('user_type');
        });
        Schema::table('site_builder_page_revisions', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->dropColumn('created_by_type');
        });
    }
};
