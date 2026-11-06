<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SavedSections;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use SiteBuilder\Core\Casts\DocumentCast;
use SiteBuilder\Core\Casts\JsonValueCast;

class SavedSectionVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'site_builder_saved_section_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'document' => DocumentCast::class, 'metadata' => JsonValueCast::class, 'source_root_ids' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Saved-section versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Saved-section versions are retained immutable provenance.'));
    }
}
