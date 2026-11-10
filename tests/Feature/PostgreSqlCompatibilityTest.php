<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use SiteBuilder\Core\Clipboard\ClipboardNamespace;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\SiteParts\ContentDependencies;
use SiteBuilder\Core\Tests\DatabaseTestCase;

class PostgreSqlCompatibilityTest extends DatabaseTestCase
{
    public function test_postgresql_clipboard_identity_preflight_and_dependency_cleanup_need_no_vendor_patch(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Targeted PostgreSQL authoring compatibility; native PostgreSQL exchange is separate.');
        }
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/site-parts-migrations'), '--realpath' => true, '--force' => true])->assertExitCode(0);
        $page = Page::factory()->create(['draft_content' => new Document]);
        $identity = app(ClipboardNamespace::class)->current();
        $this->assertSame($identity, app(ClipboardNamespace::class)->current());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $identity);
        app(ContentDependencies::class)->syncPage($page);
        $this->artisan('site-builder:canonical-check', ['--json' => true])->expectsOutputToContain('"status": "compatible"')->assertExitCode(0);
    }
}
