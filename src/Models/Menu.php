<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use SiteBuilder\Core\Casts\JsonValueCast;
use SiteBuilder\Core\Database\Factories\MenuFactory;

class Menu extends Model
{
    use HasFactory;

    protected $table = 'site_builder_menus';

    protected $fillable = ['location', 'items'];

    protected function casts(): array
    {
        return ['items' => JsonValueCast::class, 'revision' => 'integer'];
    }

    protected static function newFactory(): MenuFactory
    {
        return MenuFactory::new();
    }
}
