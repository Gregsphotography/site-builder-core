<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class SitePart extends Model
{
    protected $table = 'site_builder_site_parts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['draft_payload' => SitePartPayloadCast::class, 'write_version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SitePartRevision::class, 'resource_id')->orderByDesc('sequence');
    }

    public function editLock(): HasOne
    {
        return $this->hasOne(SitePartLock::class, 'resource_id');
    }
}
