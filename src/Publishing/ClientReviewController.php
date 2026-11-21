<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Publishing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\PageResponse;
use SiteBuilder\Core\Rendering\Seo;
use SiteBuilder\Core\Routing\PageUrlResolver;
use SiteBuilder\Core\Routing\PreviewLinks;

final class ClientReviewController
{
    public function show(Request $request, string $review, PageReviews $reviews): Response
    {
        [$snapshot, $page] = $this->open($request, $review);

        return $this->form($snapshot, $page, $reviews);
    }

    public function decide(Request $request, string $review, PageReviews $reviews): Response
    {
        [$snapshot, $page] = $this->open($request, $review);
        try {
            $decision = $reviews->decide($snapshot->id, $request->only('decision', 'reviewer', 'feedback'));
        } catch (ValidationException $invalid) {
            return $this->form($snapshot, $page, $reviews, $invalid->validator->errors()->all());
        }

        if ($decision->status === 'changes_requested') {
            return response()->view('site-builder::publishing.client-review', ['review' => $decision], 200, PageResponse::privateHeaders());
        }

        return $this->form($decision, $page, $reviews);
    }

    public function preview(Request $request, string $review, string $locale, PageResponse $renderer): Response
    {
        [$snapshot, $page] = $this->open($request, $review);
        abort_unless(in_array($locale, $this->locales($snapshot), true), 404, 'Language unavailable.', PageResponse::privateHeaders());
        $page->draft_content = $snapshot->content;
        $page->draft_settings = $snapshot->settings;

        return $renderer->render($page, $locale, true);
    }

    private function open(Request $request, string $id): array
    {
        $epoch = PreviewLinks::epoch();
        abort_unless($epoch !== '' && $request->query('epoch') === $epoch && $request->query('expires') && $request->hasValidRelativeSignature(), 403, 'Invalid or expired review link.', PageResponse::privateHeaders());
        abort_unless(PageReview::available(), 404, 'Review unavailable.', PageResponse::privateHeaders());
        $snapshot = PageReview::query()->find($id);
        abort_if($snapshot === null, 404, 'Review unavailable.', PageResponse::privateHeaders());
        abort_unless(in_array($snapshot->status, PageReview::OPEN, true) && $snapshot->expires_at > now()->timestamp, 410, 'Review closed.', PageResponse::privateHeaders());

        return [$snapshot, Page::query()->findOrFail($snapshot->page_id)];
    }

    private function locales(PageReview $snapshot): array
    {
        $catalog = app(LocaleCatalog::class);
        $urls = (array) $snapshot->settings->slugs;

        return $urls === [] ? [$catalog->default()] : array_intersect($catalog->all(), array_keys($urls));
    }

    private function form(PageReview $review, Page $page, PageReviews $reviews, array $errors = []): Response
    {
        $live = $page->publishedSettings();
        $page->draft_settings = $review->settings;
        $projected = $page->draftView();
        $languages = [];
        $differences = [];
        $urls = app(PageUrlResolver::class);
        $seo = app(Seo::class);
        $hasLivePage = $page->status === 'published';
        if ($hasLivePage && $live->title !== $review->settings->title) {
            $differences[] = ['Page title', $live->title, $review->settings->title];
        }
        foreach (app(LocaleCatalog::class)->all() as $locale) {
            $beforeUrl = $hasLivePage ? $urls->url($page, $locale) : null;
            $afterUrl = $urls->url($projected, $locale);
            $label = strtoupper($locale);
            if ($hasLivePage && $beforeUrl !== $afterUrl) {
                $differences[] = [$label.' URL', $beforeUrl, $afterUrl];
            }
            if (! in_array($locale, $this->locales($review), true)) {
                continue;
            }
            $after = $seo->metadata($projected, $locale);
            if ($beforeUrl !== null) {
                $before = $seo->metadata($page, $locale);
                foreach (['title' => 'search title', 'description' => 'search description', 'canonical' => 'canonical URL', 'robots' => 'indexing instructions', 'image' => 'social image'] as $key => $name) {
                    if ($before[$key] !== $after[$key]) {
                        $differences[] = [$label.' '.$name, $before[$key], $after[$key]];
                    }
                }
            }
            $languages[$locale] = ['preview' => $reviews->link($review, $locale), 'url' => $afterUrl, 'live' => $beforeUrl, 'seo' => $after];
        }
        $changes = [];
        $json = app(CanonicalJson::class);
        if (! $json->equals($review->content, $page->published_content)) {
            $changes[] = 'Page content';
        }
        foreach (['title' => 'Page title', 'type' => 'Page type', 'layout' => 'Layout', 'seo' => 'Search and social settings', 'schema' => 'Structured data', 'data' => 'Page data', 'slugs' => 'Localized URLs'] as $key => $label) {
            if (! $json->equals($review->settings->{$key}, $live->{$key})) {
                $changes[] = $label;
            }
        }
        $headers = PageResponse::privateHeaders();
        $headers['Content-Security-Policy'] = "form-action 'self'; frame-ancestors 'none'; base-uri 'none'";

        return response()->view('site-builder::publishing.client-review', compact('review', 'languages', 'changes', 'differences', 'hasLivePage', 'errors') + ['action' => $reviews->link($review)], $errors === [] ? 200 : 422, $headers);
    }
}
