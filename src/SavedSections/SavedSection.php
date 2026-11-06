<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SavedSections;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedSection extends Model
{
    protected $table = 'site_builder_saved_sections';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['tags' => 'array', 'write_version' => 'integer', 'current_version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SavedSectionVersion::class, 'section_id')->orderByDesc('version');
    }
}
