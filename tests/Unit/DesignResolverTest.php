<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Unit;

use InvalidArgumentException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignData;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Design\DesignTarget;
use SiteBuilder\Core\Design\ThemeIdentity;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\Fixtures\TestDesignTheme;
use SiteBuilder\Core\Tests\TestCase;

class DesignResolverTest extends TestCase
{
    private RenderContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        app(ComponentRegistry::class)->register(RenderBlock::class);
        app(DesignCatalog::class)->register(new TestDesignTheme);
        app(DesignCatalog::class)->bindDefault(fn () => new ThemeIdentity('test.theme', 1, 'test'));
        $this->context = new RenderContext('en');
    }

    private function document(): Document
    {
        return new Document([new Node('section', 'test.block', props: new JsonObject(['heading' => 'Keep']))]);
    }

    public function test_untouched_variant_defaults_never_emit_control_classes_and_one_edit_only_activates_its_control(): void
    {
        $resolver = app(DesignResolver::class);
        $document = $this->document();
        $this->assertSame([], $resolver->resolve($document, $document->nodes[0], $this->context)->attributes);
        $changed = app(DesignOperations::class)->set($document, DesignTarget::document(), 'width', 'narrow', $this->context);
        $presentation = $resolver->resolve($changed, $changed->nodes[0], $this->context);
        $this->assertStringContainsString('test-design-base-width-narrow', $presentation->attributes['class']);
        $this->assertStringNotContainsString('-align-', $presentation->attributes['class']);
        $this->assertStringNotContainsString('-surface-', $presentation->attributes['class']);
        $this->assertArrayNotHasKey('class', $resolver->resolve($changed, null, $this->context)->attributes);
    }

    public function test_node_base_overrides_page_responsive_default_at_every_later_breakpoint_and_reset_restores_inheritance(): void
    {
        $ops = app(DesignOperations::class);
        $document = $ops->set($this->document(), DesignTarget::document('wide'), 'align', 'end', $this->context);
        $document = $ops->set($document, DesignTarget::node('section'), 'align', 'center', $this->context);
        $resolved = app(DesignResolver::class)->resolve($document, $document->nodes[0], $this->context);
        foreach (['base', 'compact', 'wide'] as $breakpoint) {
            $this->assertSame('center', $resolved->states[$breakpoint]['values']['align']);
            $this->assertStringContainsString('test-design-'.$breakpoint.'-align-center', $resolved->attributes['class']);
        }
        $document = $ops->reset($document, DesignTarget::node('section'), 'align', $this->context);
        $this->assertNull(DesignData::payload($document->nodes[0]->extensions));
        $resolved = app(DesignResolver::class)->resolve($document, $document->nodes[0], $this->context);
        $this->assertSame('end', $resolved->states['wide']['values']['align']);
        $this->assertSame('Page at 72rem+', $resolved->states['wide']['sources']['align']);
        $this->assertStringNotContainsString('test-design-base-align', $resolved->attributes['class']);
        $this->assertStringContainsString('test-design-wide-align-end', $resolved->attributes['class']);
    }

    public function test_atomic_combinations_and_scope_reset_preserve_content_and_the_theme_pin(): void
    {
        $ops = app(DesignOperations::class);
        $before = $this->document();
        $document = $ops->patch($before, DesignTarget::document(), ['surface' => 'accent', 'text' => 'on-accent'], $this->context);
        $document = $ops->set($document, DesignTarget::document('wide'), 'space', 'lg', $this->context);
        $cleared = $ops->resetScope($document, DesignTarget::document(), $this->context);
        $this->assertTrue(app(CanonicalJson::class)->equals($before->nodes, $cleared->nodes));
        $payload = DesignData::payload($cleared->extensions);
        $this->assertSame('test.theme', $payload->theme->id);
        $this->assertObjectNotHasProperty('base', $payload);
        $this->assertObjectNotHasProperty('responsive', $payload);
        $this->expectException(InvalidArgumentException::class);
        $ops->set($before, DesignTarget::document(), 'text', 'on-accent', $this->context);
    }

    public function test_theme_appearance_change_is_reviewed_even_when_option_ids_do_not_change(): void
    {
        $report = app(DesignOperations::class)->report($this->document(), new ThemeIdentity('test.theme', 1, 'alternate'), $this->context);
        $this->assertTrue($report->compatible);
        $this->assertArrayHasKey('theme', $report->changedDefaults);
        $this->assertSame('alternate', $report->changedDefaults['theme']['after']['preset']);
    }

    public function test_unknown_envelopes_and_unavailable_node_definitions_are_not_exposed_as_editable_design(): void
    {
        $base = $this->document();
        $node = $base->nodes[0];
        $unknown = new Document([new Node($node->id, $node->type, extensions: new JsonObject(['site-builder.render' => (object) ['version' => 999, 'design' => (object) ['version' => 1]]]))]);
        $description = app(DesignResolver::class)->describe($unknown, DesignTarget::node('section'), $this->context);
        $this->assertFalse($description['available']);
        $this->assertSame([], $description['controls']);
        $this->assertContains('unsupported_extension', array_map(fn ($diagnostic) => $diagnostic->code, $description['diagnostics']));
        $unknownComponent = new Document([new Node('opaque', 'future.block')]);
        $this->assertFalse(app(DesignResolver::class)->describe($unknownComponent, DesignTarget::node('opaque'), $this->context)['available']);
    }
}
