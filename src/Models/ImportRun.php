<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use SiteBuilder\Core\Casts\JsonValueCast;
use SiteBuilder\Core\Database\Factories\ImportRunFactory;

class ImportRun extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'site_builder_import_runs';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['summary' => JsonValueCast::class, 'format_version' => 'integer', 'mapper_version' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    protected static function newFactory(): ImportRunFactory
    {
        return ImportRunFactory::new();
    }
}
