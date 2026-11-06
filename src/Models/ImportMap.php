<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SiteBuilder\Core\Database\Factories\ImportMapFactory;

class ImportMap extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'site_builder_import_map';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['imported_write_version' => 'integer'];
    }

    protected static function newFactory(): ImportMapFactory
    {
        return ImportMapFactory::new();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }
}
