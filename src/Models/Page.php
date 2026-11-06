<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use SiteBuilder\Core\Casts\DocumentCast;
use SiteBuilder\Core\Casts\JsonValueCast;
use SiteBuilder\Core\Database\Factories\PageFactory;

class Page extends Model
{
    use HasFactory;

    protected $table = 'site_builder_pages';

    protected $fillable = ['title', 'type', 'layout', 'seo', 'schema', 'data'];

    protected function casts(): array
    {
        return ['draft_content' => DocumentCast::class, 'published_content' => DocumentCast::class,
            'seo' => JsonValueCast::class, 'schema' => JsonValueCast::class, 'data' => JsonValueCast::class,
            'published_at' => 'immutable_datetime', 'write_version' => 'integer', 'published_from_version' => 'integer'];
    }

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    public function slugs(): HasMany
    {
        return $this->hasMany(PageSlug::class);
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(PageRedirect::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->latest('id');
    }

    public function editLock(): HasOne
    {
        return $this->hasOne(PageLock::class);
    }
}
