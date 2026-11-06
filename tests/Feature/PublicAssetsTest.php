<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use InvalidArgumentException;
use SiteBuilder\Core\Rendering\PublicAsset;
use SiteBuilder\Core\Rendering\PublicAssetRegistry;
use SiteBuilder\Core\Rendering\PublicAssets;
use SiteBuilder\Core\Tests\TestCase;

class PublicAssetsTest extends TestCase
{
    public function test_response_scope_deduplicates_and_restores_after_nested_scopes_and_failures(): void
    {
        $registry = app(PublicAssetRegistry::class);
        $registry->register('host.module', new PublicAsset('module', '/vendor/host/module.js?v='.str_repeat('a', 16)));
        $registry->register('host.styles', new PublicAsset('stylesheet', '/vendor/host/style.css'));
        $assets = app(PublicAssets::class);
        $assets->scope(function () use ($assets): void {
            $assets->request('host.module');
            $assets->request('host.module');
            try {
                $assets->scope(function () use ($assets): void {
                    $assets->request('host.styles');
                    throw new \RuntimeException('Host render failed.');
                });
            } catch (\RuntimeException) {
            }
            $this->assertSame(1, substr_count((string) $assets->html(), '<script'));
            $this->assertSame('', (string) $assets->html());
            $assets->request('host.styles');
            $this->assertSame('<link rel="stylesheet" href="/vendor/host/style.css">', (string) $assets->html());
        });
        $this->assertSame('', (string) $assets->html());
        $assets->scope(function () use ($assets): void {
            $this->assertSame('', (string) $assets->html());
            $assets->request('host.module');
            $this->assertSame(1, substr_count((string) $assets->html(), '<script'));
        });
    }

    public function test_untrusted_urls_duplicate_ids_and_unknown_requests_reject(): void
    {
        foreach (['https://cdn.example.test/x.js', '//example.test/x.js', '/../../x.js', '/asset.js?nonce=secret', '/a.js" onload="evil', '/a\\b.js'] as $url) {
            try {
                new PublicAsset('module', $url);
                $this->fail('Untrusted URL accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $registry = app(PublicAssetRegistry::class);
        $registry->register('host', new PublicAsset('module', '/host.js'));
        try {
            $registry->register('host', new PublicAsset('module', '/other.js'));
            $this->fail('Duplicate accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        app(PublicAssets::class)->request('unknown');
    }
}
