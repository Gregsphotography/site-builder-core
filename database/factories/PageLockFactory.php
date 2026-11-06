<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Models\PageLock;

/** @extends Factory<PageLock> */
final class PageLockFactory extends Factory
{
    protected $model = PageLock::class;

    public function definition(): array
    {
        return ['page_id' => Page::factory(), 'user_id' => 1, 'token_hash' => hash('sha256', (string) Str::uuid()), 'expires_at' => now()->addMinutes(5)];
    }
}
