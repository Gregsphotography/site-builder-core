<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SiteBuilder\Core\Casts\DocumentCast;
use SiteBuilder\Core\Database\Factories\PageRevisionFactory;

class PageRevision extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'site_builder_page_revisions';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['content' => DocumentCast::class, 'created_at' => 'immutable_datetime', 'created_by' => 'string', 'published_from_version' => 'integer'];
    }

    protected static function newFactory(): PageRevisionFactory
    {
        return PageRevisionFactory::new();
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
