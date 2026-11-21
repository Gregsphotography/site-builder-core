<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use SiteBuilder\Core\Casts\DocumentCast;
use SiteBuilder\Core\Casts\JsonValueCast;
use SiteBuilder\Core\Database\Factories\PageFactory;
use stdClass;

class Page extends Model
{
    use HasFactory;

    protected $table = 'site_builder_pages';

    public const SETTINGS_FIELDS = ['title', 'type', 'layout', 'seo', 'schema', 'data'];

    protected $fillable = self::SETTINGS_FIELDS;

    protected function casts(): array
    {
        return ['draft_content' => DocumentCast::class, 'published_content' => DocumentCast::class,
            'seo' => JsonValueCast::class, 'schema' => JsonValueCast::class, 'data' => JsonValueCast::class, 'draft_settings' => JsonValueCast::class,
            'published_at' => 'immutable_datetime', 'write_version' => 'integer', 'published_from_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('active', fn (Builder $query) => $query->where($query->qualifyColumn('status'), '!=', 'trashed'));
    }

    public static function trash(): Builder
    {
        return static::query()->withoutGlobalScope('active')->where('status', 'trashed');
    }

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    public function slugs(): HasMany
    {
        return $this->hasMany(PageSlug::class);
    }

    public function publishedSettings(): stdClass
    {
        return (object) [...$this->only(self::SETTINGS_FIELDS), 'slugs' => (object) $this->slugs->pluck('slug', 'locale')->all()];
    }

    public function draftLayout(): ?string
    {
        return $this->draft_settings === null ? $this->layout : $this->draft_settings->layout;
    }

    /** Project staged settings for read-only authoring and previews. Public queries use the original page. */
    public function draftView(): self
    {
        $page = clone $this;
        $settings = $this->draft_settings;
        if ($settings !== null) {
            $page->fill(array_diff_key((array) $settings, ['slugs' => true]));
            $urls = [];
            foreach ($settings->slugs as $locale => $slug) {
                $urls[] = new PageSlug(['page_id' => $this->id, 'locale' => $locale, 'slug' => $slug]);
            }
            $page->setRelation('slugs', (new PageSlug)->newCollection($urls));
        }

        return $page;
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
