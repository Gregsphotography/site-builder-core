<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Feature;

use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Components\PropertyDefinition;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Content\JsonObject;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Exceptions\InvalidDocument;
use SiteBuilder\Core\Rendering\DocumentRenderer;
use SiteBuilder\Core\Rendering\PropertyResolver;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Rendering\RichTextSanitizer;
use SiteBuilder\Core\Rendering\SafeUrl;
use SiteBuilder\Core\Tests\Fixtures\RenderBlock;
use SiteBuilder\Core\Tests\TestCase;

class RenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['site-builder.locales' => ['en' => [], 'de' => [], 'fr' => []]]);
        app(ComponentRegistry::class)->register(RenderBlock::class);
        view()->addNamespace('test-blocks', __DIR__.'/../Fixtures/views');
    }

    public function test_recursive_rendering_localizes_without_defaults_and_skips_hidden_subtrees(): void
    {
        $child = new Node('child', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Child']]));
        $hidden = new Node('hidden', 'test.block', props: new JsonObject(['heading' => 'Secret']), visibility: 'hidden', children: [$child]);
        $root = new Node('parent', 'test.block', props: new JsonObject(['heading' => 'Base', 'body' => '<p>Base</p>', 'tone' => 'brand']), translations: new JsonObject(['en' => (object) ['heading' => 'Default', 'body' => '<p>Hello <strong>world</strong></p>'], 'de' => (object) ['heading' => null], 'fr' => (object) ['heading' => '', 'items' => []]]), children: [new Node('visible', 'test.block', props: new JsonObject(['heading' => 'Second']))]);
        $doc = new Document([$root, $hidden, new Node('empty', 'test.block')]);
        $before = app(CanonicalJson::class)->digest($doc);
        $renderer = app(DocumentRenderer::class);
        $de = $renderer->render($doc, new RenderContext('de'));
        $this->assertStringContainsString('<h2>Default</h2>', (string) $de->html);
        $this->assertStringContainsString('<strong>world</strong>', (string) $de->html);
        $this->assertStringContainsString('data-site-builder-node="visible"', (string) $de->html);
        $this->assertStringNotContainsString('Secret', (string) $de->html);
        $this->assertStringNotContainsString('Child', (string) $de->html);
        $this->assertStringNotContainsString('Never inject defaults', (string) $de->html);
        $this->assertCount(3, $de->structuredData);
        $fr = $renderer->render($doc, new RenderContext('fr', 'preview'));
        $this->assertStringContainsString('<h2></h2>', (string) $fr->html);
        $this->assertSame([], $fr->structuredData);
        $this->assertSame($before, app(CanonicalJson::class)->digest($doc));
    }

    public function test_html_links_media_and_text_are_safe_even_for_legacy_unvalidated_content(): void
    {
        $doc = new Document([new Node('safe', 'test.block', props: new JsonObject(['heading' => '<img src=x onerror=evil()>', 'body' => '<script>evil()</script><p onclick="evil()" style="color:red">Hello <a href="//evil.test">bad</a><a href="/safe">good</a><a href="#part">part</a></p>', 'link' => 'javascript:evil()', 'image' => '//evil.test/x']))]);
        $html = (string) app(DocumentRenderer::class)->render($doc, new RenderContext('en'))->html;
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('href="/safe"', $html);
        $this->assertStringContainsString('href="#part"', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<p onclick', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('href="//', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('src="//', $html);
        foreach (['javascript:alert(1)', '//evil.test', '/%2Fevil.test', '/\\evil.test', 'java%0ascript:x', 'data:text/html,a', ' /safe'] as $url) {
            $this->assertFalse(app(SafeUrl::class)->valid($url), $url);
        }
        foreach (['https://example.test/path', 'http://example.test', '/nested/path', '/images/my%20photo.jpg', 'https://example.test/?q=hello%20there', '#part', 'mailto:hello@example.test', 'tel:+41440000000'] as $url) {
            $this->assertTrue(app(SafeUrl::class)->valid($url), $url);
        }
    }

    public function test_imported_rich_text_containers_retain_their_visible_content(): void
    {
        $html = app(RichTextSanitizer::class)->sanitize('<div id="legacy"><section><h1>Policy</h1><p>Current <sup>2</sup> text</p><details><summary>Service</summary><p>Policy detail</p></details><small>Updated today</small><script>evil()</script></section></div>');
        $this->assertStringContainsString('<h1>Policy</h1>', $html);
        $this->assertStringContainsString('<p>Current <sup>2</sup> text</p>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('id="legacy"', $html);
        $this->assertStringContainsString('<small>Updated today</small>', $html);
        $this->assertStringContainsString('<p>Policy detail</p>', $html);
    }

    public function test_repeater_fallback_is_whole_list_and_shared_values_cannot_be_translated(): void
    {
        $node = new Node('list', 'test.block', props: new JsonObject(['tone' => 'brand', 'space' => 'space.section']), translations: new JsonObject(['en' => (object) ['items' => [(object) ['label' => 'One'], (object) ['label' => 'Two']]], 'de' => (object) ['items' => [(object) ['label' => 'Eins']]], 'fr' => (object) ['items' => []]]));
        $resolver = app(PropertyResolver::class);
        $definition = app(ComponentRegistry::class)->resolve('test.block');
        $this->assertCount(1, $resolver->resolve($node, $definition, 'de')['items']);
        $this->assertCount(2, $resolver->resolve($node, $definition, 'it')['items']);
        $this->assertSame([], $resolver->resolve($node, $definition, 'fr')['items']);
        $this->assertSame('6rem', $resolver->resolve($node, $definition, 'de')['space']);
        $this->expectException(InvalidDocument::class);
        app(DocumentValidator::class)->validate(new Document([new Node('bad', 'test.block', translations: new JsonObject(['de' => (object) ['tone' => 'neutral']]))]));
    }

    public function test_validation_checks_all_locales_nested_properties_and_required_publication_values(): void
    {
        $validator = app(DocumentValidator::class);
        $doc = new Document([new Node('a', 'test.block', translations: new JsonObject(['en' => (object) ['heading' => 'Good'], 'xx' => (object) ['items' => [(object) ['url' => 'javascript:x']]]]))]);
        try {
            $validator->validate($doc);
            $this->fail('Unsafe unconfigured-locale list values must fail.');
        } catch (InvalidDocument $exception) {
            $this->assertSame('/nodes/0/translations/xx/items/0/url', $exception->diagnostics[0]->path);
        }
        $this->assertSame([], $validator->validate(new Document([new Node('a', 'test.block')])));
        $this->expectException(InvalidDocument::class);
        $validator->validate(new Document([new Node('a', 'test.block')]), true, ['en']);
    }

    public function test_unknown_content_is_diagnosed_and_never_executed_or_discarded(): void
    {
        $doc = new Document([new Node('a', 'unknown', props: new JsonObject(['view' => 'anything', 'raw' => (object) ['nested' => []]])), new Node('b', 'test.block', 99)]);
        $before = json_encode($doc);
        $result = app(DocumentRenderer::class)->render($doc, new RenderContext('en'));
        $this->assertSame('', (string) $result->html);
        $this->assertCount(2, $result->diagnostics);
        $this->assertSame($before, json_encode($doc));
        $this->expectException(InvalidDocument::class);
        app(DocumentValidator::class)->validate($doc, true);
    }

    public function test_publication_requirements_are_recursive_and_reserved_render_extensions_are_not_executed(): void
    {
        app(ComponentRegistry::class)->register(new class extends RenderBlock
        {
            public function type(): string
            {
                return 'test.required';
            }

            public function properties(): array
            {
                return ['cards' => PropertyDefinition::list(PropertyDefinition::object(['label' => PropertyDefinition::text(requiredForPublish: true)]))];
            }
        });
        $doc = new Document([new Node('a', 'test.required', props: new JsonObject(['cards' => [(object) []]]))]);
        app(DocumentValidator::class)->validate($doc);
        try {
            app(DocumentValidator::class)->validate($doc, true);
            $this->fail('Nested publication requirement was ignored.');
        } catch (InvalidDocument $exception) {
            $this->assertSame('required_for_publish', $exception->diagnostics[0]->code);
            $this->assertSame('/nodes/0/en/cards/0/label', $exception->diagnostics[0]->path);
        }
        $unsupported = new Document([new Node('a', 'test.block', props: new JsonObject(['heading' => 'Never execute']), extensions: new JsonObject(['site-builder.render' => (object) ['adapter' => 'future']]))]);
        $this->assertSame('', (string) app(DocumentRenderer::class)->render($unsupported, new RenderContext('en'))->html);
        $this->expectException(InvalidDocument::class);
        app(DocumentValidator::class)->validate($unsupported, true);
    }
}
