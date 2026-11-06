<?php

declare(strict_types=1);

namespace SiteBuilder\Core\DraftHistory;

use Illuminate\Database\Eloquent\Model;

/** Operational private data: never serialize this model into an editor response. */
final class HistoryRecord extends Model
{
    protected $table = 'site_builder_draft_histories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['actor_id', 'actor_type', 'lease_hash', 'checkpoints', 'actions', 'group_state'];

    protected function casts(): array
    {
        return ['page_id' => 'integer', 'sequence' => 'integer', 'cursor' => 'integer', 'accepted_version' => 'integer', 'evicted' => 'integer', 'checkpoints' => 'array', 'actions' => 'array', 'group_state' => 'array', 'expires_at' => 'immutable_datetime'];
    }
}
