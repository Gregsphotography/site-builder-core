<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SiteBuilder\Core\Database\Factories\PageLockFactory;

class PageLock extends Model
{
    use HasFactory;

    protected $hidden = ['token_hash'];

    protected $table = 'site_builder_page_locks';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['user_id' => 'string', 'expires_at' => 'immutable_datetime'];
    }

    protected static function newFactory(): PageLockFactory
    {
        return PageLockFactory::new();
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
