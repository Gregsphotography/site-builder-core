<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Database\Eloquent\Model;
use SiteBuilder\Core\Composition\PublishedPartReference;

final class SitePartRevision extends Model
{
    protected $table = 'site_builder_site_part_revisions';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['payload' => SitePartPayloadCast::class, 'sequence' => 'integer', 'published_from_version' => 'integer', 'published_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    public function reference(): PublishedPartReference
    {
        return new PublishedPartReference($this->resource_id, $this->id, $this->digest);
    }
}
