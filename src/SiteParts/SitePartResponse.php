<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Components\ComponentRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\Node;
use SiteBuilder\Core\Contracts\HasPlacementPolicy;
use SiteBuilder\Core\Design\DesignCatalog;
use SiteBuilder\Core\Design\DesignResolver;
use SiteBuilder\Core\Rendering\DocumentRenderer;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\PageResponse;
use SiteBuilder\Core\Rendering\PublicAssets;

final class SitePartResponse
{
    public function __construct(private SitePartAccess $access, private SitePartCommands $commands, private DocumentRenderer $renderer, private LayoutRegistry $layouts) {}

    public function preview(Authenticatable $actor, string $partId, string $locale, ?string $revisionId = null): Response
    {
        return app(PublicAssets::class)->scope(fn (): Response => $this->renderPreview($actor, $partId, $locale, $revisionId));
    }

    private function renderPreview(Authenticatable $actor, string $partId, string $locale, ?string $revisionId): Response
    {
        $part = SitePart::query()->findOrFail($partId);
        $this->access->authorize($actor, 'preview', $part);
        if (! app(LocaleCatalog::class)->has($locale)) {
            abort(404);
        }
        $payload = $part->draft_payload;
        if ($revisionId !== null) {
            $revision = $part->revisions()->findOrFail($revisionId);
            if ($revision->withdrawn_at !== null || ! hash_equals($revision->digest, app(CanonicalJson::class)->digest($revision->payload))) {
                return response('The immutable site-part revision is unavailable.', 503, PageResponse::privateHeaders());
            }
            $payload = $revision->payload;
        }
        $context = $this->commands->context($locale);
        $document = $payload->validationDocument();
        if ($part->kind === 'design') {
            $theme = app(DesignResolver::class)->theme($document, $context);
            $themeDefinition = $theme === null ? null : app(DesignCatalog::class)->definition($theme);
            foreach (app(ComponentRegistry::class)->all() as $definition) {
                if ($themeDefinition?->supports($definition->type(), $definition->version(), null) && $definition->allowedChildren()->allowsCount(0) && (! $definition instanceof HasPlacementPolicy || $definition->placement()->rootAllowed)) {
                    $document = new Document([new Node('design-preview', $definition->type(), $definition->version(), $definition->defaults()->props, $definition->defaults()->translations)], $document->extensions);
                    break;
                }
            }
        }
        try {
            $render = $this->renderer->render($document, $context);
            if (array_any($render->diagnostics, fn ($diagnostic) => in_array($diagnostic->code, ['unavailable_composition', 'unavailable_design_theme', 'invalid_design_structure', 'incompatible_design_value', 'incompatible_design_component', 'incompatible_design_combination'], true))) {
                return response('Required site-part composition or design is unavailable.', 503, PageResponse::privateHeaders());
            }
            $html = view($this->layouts->view($context->layout), ['slot' => $render->html, 'headerSlot' => $render->headerSlot, 'footerSlot' => $render->footerSlot, 'part' => $part, 'page' => null, 'locale' => $locale, 'title' => $part->name, 'seo' => ['title' => $part->name, 'robots' => 'noindex, nofollow', 'preview' => true], 'context' => $context, 'preview' => true, 'diagnostics' => $render->diagnostics])->render();
            $guard = '<script type="module" src="'.e(asset('vendor/site-builder/preview.js')).'"></script>';
            $html = str_contains($html, '</body>') ? str_replace('</body>', $guard.'</body>', $html) : $html.$guard;

            return response($html, 200, PageResponse::privateHeaders());
        } catch (ValidationException|\InvalidArgumentException) {
            return response('Required immutable site-part data is unavailable.', 503, PageResponse::privateHeaders());
        }
    }
}
