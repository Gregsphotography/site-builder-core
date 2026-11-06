<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SiteBuilder\Core\Database\Factories\PageRedirectFactory;

class PageRedirect extends Model
{
    use HasFactory;

    protected $table = 'site_builder_page_redirects';

    protected $fillable = ['page_id', 'locale', 'from_slug', 'status'];

    protected function casts(): array
    {
        return ['status' => 'integer'];
    }

    protected static function newFactory(): PageRedirectFactory
    {
        return PageRedirectFactory::new();
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
