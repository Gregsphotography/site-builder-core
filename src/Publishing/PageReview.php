<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Publishing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use SiteBuilder\Core\Casts\DocumentCast;
use SiteBuilder\Core\Casts\JsonValueCast;

final class PageReview extends Model
{
    use HasUuids;

    protected $table = 'site_builder_page_reviews';

    public const OPEN = ['pending', 'approved', 'scheduled'];

    protected function casts(): array
    {
        return ['draft_version' => 'integer', 'page_id' => 'integer', 'content' => DocumentCast::class, 'settings' => JsonValueCast::class,
            'expires_at' => 'integer', 'scheduled_for' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    public static function available(): bool
    {
        return Schema::hasTable('site_builder_page_reviews');
    }

    /** The caller holds the page row lock. Withdrawing a page cancels every pending version. */
    public static function cancelOpen(int $pageId, ?int $throughVersion = null): void
    {
        if (self::available()) {
            $open = self::query()->where('page_id', $pageId)->whereIn('status', self::OPEN);
            if ($throughVersion !== null) {
                $open->where('draft_version', '<=', $throughVersion);
            }
            $open->update(['status' => 'cancelled']);
        }
    }
}
