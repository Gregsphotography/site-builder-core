<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Composition\CompositionData;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Design\DesignOperations;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageAccess;

final class PageResponse
{
    public function __construct(private DocumentRenderer $renderer, private LayoutRegistry $layouts, private Seo $seo) {}

    /** Authorized read-only source preview using the actual destination wrapper. */
    public function previewDocument(Authenticatable $actor, int $pageId, Document $document, string $locale, ?array $rootIds = null): Response
    {
        $page = Page::query()->findOrFail($pageId);
        app(PageAccess::class)->authorize($actor, 'preview', $page);
        if (! app(LocaleCatalog::class)->has($locale)) {
            abort(404);
        }
        if ($rootIds === null) {
            app(RenderFeatureRegistry::class)->projectSections($document);
        }
        $context = new RenderContext($locale, 'preview', $page->id, $page->layout);
        if ($rootIds === null) {
            $document = app(DesignOperations::class)->admitComposition($page->draft_content ?? new Document, $document->nodes, $context);
        }
        app(DocumentValidator::class)->validate($document, context: $context);
        $contextPage = clone $page;
        $contextPage->draft_content = $document;

        return $this->render($contextPage, $locale, true, rootIds: $rootIds);
    }

    public function render(Page $page, string $locale, bool $preview = false, ?array $editor = null, ?array $rootIds = null): Response
    {
        return app(PublicAssets::class)->scope(fn (): Response => $this->renderResponse($page, $locale, $preview, $editor, $rootIds));
    }

    private function renderResponse(Page $page, string $locale, bool $preview, ?array $editor, ?array $rootIds): Response
    {
        $context = new RenderContext($locale, $preview ? 'preview' : 'public', (int) $page->id, $page->layout);
        $previous = app()->getLocale();
        try {
            app()->setLocale($locale);
            $result = $this->renderer->render(($preview ? $page->draft_content : $page->published_content) ?? new Document, $context, $rootIds);
            $requiredCompositionUnavailable = CompositionData::payload(($preview ? $page->draft_content : $page->published_content) ?? new Document) !== null && app(RenderFeatureRegistry::class)->readOnly(($preview ? $page->draft_content : $page->published_content) ?? new Document);
            if ($requiredCompositionUnavailable || array_any($result->diagnostics, fn ($diagnostic): bool => in_array($diagnostic->code, ['unavailable_composition', 'missing_composition_capability', 'unavailable_design_theme', 'invalid_design_structure', 'incompatible_design_value', 'incompatible_design_component', 'incompatible_design_breakpoint', 'incompatible_design_combination', 'invalid_theme_mapping'], true))) {
                return response('Required design/theme data or compatible assets are unavailable. Restore the pinned version or resolve the draft diagnostics.', 503, $preview ? self::privateHeaders() : ['Cache-Control' => 'private, no-store']);
            }
            $seo = $this->seo->forPage($page, $context, $result);
            $html = view($this->layouts->view($page->layout), ['slot' => $result->html, 'headerSlot' => $result->headerSlot, 'footerSlot' => $result->footerSlot, 'page' => $page, 'locale' => $locale, 'title' => $seo['title'], 'seo' => $seo, 'context' => $context, 'preview' => $preview, 'diagnostics' => $result->diagnostics])->render();
            if ($preview) {
                $assetVersion = substr(hash_file('sha256', __DIR__.'/../../resources/dist/preview.js'), 0, 16);
                $guard = '<script type="module" src="'.e(asset('vendor/site-builder/preview.js').'?v='.$assetVersion).'"></script>';
                if ($editor !== null) {
                    $guard = '<script id="site-builder-preview-context" type="application/json">'.json_encode($editor, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'</script>'.$guard;
                }
                $html = str_contains($html, '</body>') ? str_replace('</body>', $guard.'</body>', $html) : $html.$guard;
            }

            return response($html, 200, $preview ? self::privateHeaders() : ['Cache-Control' => app(PublicAssets::class)->privateResponse() ? 'private, no-store' : 'public, max-age=0, must-revalidate']);
        } catch (ValidationException|\InvalidArgumentException $exception) {
            $document = ($preview ? $page->draft_content : $page->published_content) ?? new Document;
            if (CompositionData::payload($document) === null && ! CompositionData::hasReferences($document->nodes)) {
                throw $exception;
            }

            return response('Required immutable composition data is unavailable. Restore the pinned version or resolve draft diagnostics.', 503, $preview ? self::privateHeaders() : ['Cache-Control' => 'private, no-store']);
        } finally {
            app()->setLocale($previous);
        }
    }

    public static function privateHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'SAMEORIGIN', 'Content-Security-Policy' => "form-action 'none'; frame-ancestors 'self'"];
    }
}
