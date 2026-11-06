<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SiteBuilder\Core\Database\Factories\PageSlugFactory;

class PageSlug extends Model
{
    use HasFactory;

    protected $table = 'site_builder_page_slugs';

    protected $fillable = ['page_id', 'locale', 'slug'];

    protected function casts(): array
    {
        return [];
    }

    protected static function newFactory(): PageSlugFactory
    {
        return PageSlugFactory::new();
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
