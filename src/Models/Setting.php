<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use SiteBuilder\Core\Casts\JsonValueCast;
use SiteBuilder\Core\Database\Factories\SettingFactory;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'site_builder_settings';

    protected $fillable = ['namespace', 'data'];

    protected function casts(): array
    {
        return ['data' => JsonValueCast::class, 'revision' => 'integer'];
    }

    protected static function newFactory(): SettingFactory
    {
        return SettingFactory::new();
    }
}
