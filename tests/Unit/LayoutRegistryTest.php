<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Unit;

use InvalidArgumentException;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Tests\TestCase;

class LayoutRegistryTest extends TestCase
{
    public function test_trusted_config_accepts_registered_keys_and_legacy_literal_blade_views(): void
    {
        $layouts = app(LayoutRegistry::class);
        $layouts->register('brand.minimal', 'site-builder::layouts.site');
        config(['site-builder.layout' => 'brand.minimal']);
        $this->assertSame('site-builder::layouts.site', $layouts->view(null));
        $this->assertSame('site-builder::layouts.site', $layouts->view('brand.minimal'));
        config(['site-builder.layout' => 'site-builder::layouts.site']);
        $this->assertSame('site-builder::layouts.site', $layouts->view(null));
    }

    public function test_stored_content_still_cannot_select_a_literal_blade_view(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(LayoutRegistry::class)->view('site-builder::layouts.site');
    }
}
