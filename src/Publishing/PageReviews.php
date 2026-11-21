<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Publishing;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use SiteBuilder\Core\Composition\CompositionResolver;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Content\DocumentValidator;
use SiteBuilder\Core\Exceptions\WriteConflict;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\ActorIdentity;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\Persistence\PageLocks;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\RenderContext;
use SiteBuilder\Core\Routing\PreviewLinks;
use SiteBuilder\Core\SiteParts\ContentDependencies;

final class PageReviews
{
    public function request(Authenticatable $actor, int $pageId, string $lease, int $version): PageReview
    {
        return DB::transaction(function () use ($actor, $pageId, $lease, $version): PageReview {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $identity = app(PageAccess::class)->authorize($actor, 'update', $page);
            app(PageAccess::class)->authorize($actor, 'preview', $page);
            app(PageLocks::class)->assertHeld($page, $identity, $lease);
            if ($version !== $page->write_version) {
                throw new WriteConflict('stale_version', $page->write_version);
            }
            $this->signingEpoch();
            $settings = $page->draft_settings ?? $page->publishedSettings();
            app(PageCommands::class)->validateSettingsSnapshot($settings);
            $locales = app(LocaleCatalog::class);
            $context = new RenderContext($locales->default(), 'public', $pageId, $settings->layout);
            $document = $page->draft_content ?? new Document;
            app(CompositionResolver::class)->lockMutation($document, $document, $context, $actor);
            app(DocumentValidator::class)->validate($document, true, array_intersect($locales->all(), array_keys((array) $settings->slugs)), $context);
            $existing = PageReview::query()->where('page_id', $pageId)->where('draft_version', $version)
                ->where('status', 'pending')->where('expires_at', '>', now()->timestamp)->first();
            if ($existing !== null) {
                return $existing;
            }
            $review = (new PageReview)->forceFill(['page_id' => $pageId, 'draft_version' => $version, 'content' => $page->draft_content,
                'settings' => $settings, 'status' => 'pending', 'requested_by' => $identity->id, 'requested_by_type' => $identity->type,
                'expires_at' => now()->addDays(7)->timestamp]);
            $review->save();
            app(ContentDependencies::class)->syncPage($page);

            return $review;
        }, 3);
    }

    public function link(PageReview $review, ?string $locale = null): string
    {
        $route = $locale === null ? 'site-builder.review' : 'site-builder.review.preview';
        $params = ['review' => $review->id, 'epoch' => $this->signingEpoch()];
        if ($locale !== null) {
            $params['locale'] = $locale;
        }

        return url(URL::temporarySignedRoute($route, CarbonImmutable::createFromTimestamp($review->expires_at), $params, absolute: false));
    }

    private function signingEpoch(): string
    {
        $epoch = PreviewLinks::epoch();
        if ($epoch === '') {
            throw ValidationException::withMessages(['review' => 'Client review links are disabled in the site preview configuration.']);
        }

        return $epoch;
    }

    public function decide(string $id, array $input): PageReview
    {
        $values = Validator::make($input, ['decision' => 'required|in:approved,changes_requested', 'reviewer' => 'required|string|max:120', 'feedback' => 'required_if:decision,changes_requested|nullable|string|max:4000'])->validate();

        return DB::transaction(function () use ($id, $values): PageReview {
            $pageId = PageReview::query()->whereKey($id)->value('page_id');
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $review = PageReview::query()->where('page_id', $pageId)->lockForUpdate()->findOrFail($id);
            abort_unless($review->status === 'pending' && $review->expires_at > now()->timestamp, 410);
            $review->forceFill(['status' => $values['decision'], 'reviewer' => $values['reviewer'], 'feedback' => $values['feedback'] ?? null, 'decided_at' => now()])->save();
            app(ContentDependencies::class)->syncPage($page);

            return $review;
        }, 3);
    }

    public function cancel(Authenticatable $actor, int $pageId, string $id): void
    {
        DB::transaction(function () use ($actor, $pageId, $id): void {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $review = PageReview::query()->where('page_id', $pageId)->lockForUpdate()->findOrFail($id);
            app(PageAccess::class)->authorize($actor, $review->status === 'scheduled' ? 'publish' : 'update', $page);
            if (in_array($review->status, PageReview::OPEN, true)) {
                $review->forceFill(['status' => 'cancelled'])->save();
                app(ContentDependencies::class)->syncPage($page);
            }
        }, 3);
    }

    public function schedule(Authenticatable $actor, int $pageId, string $id, CarbonImmutable $time, string $guard): void
    {
        DB::transaction(function () use ($actor, $pageId, $id, $time, $guard): void {
            $page = Page::query()->lockForUpdate()->findOrFail($pageId);
            $identity = app(PageAccess::class)->authorize($actor, 'publish', $page);
            $review = PageReview::query()->where('page_id', $pageId)->lockForUpdate()->findOrFail($id);
            if (! in_array($review->status, ['approved', 'scheduled'], true) || $time->isPast()) {
                throw ValidationException::withMessages(['schedule' => 'Choose an approved version and a future publication time.']);
            }
            $this->publisher($guard, $identity);
            $review->forceFill(['status' => 'scheduled', 'scheduled_for' => $time->timestamp, 'publisher' => $identity->id,
                'publisher_type' => $identity->type, 'publisher_guard' => $guard, 'failure' => null])->save();
        }, 3);
    }

    public function expire(PageReview $candidate): void
    {
        DB::transaction(function () use ($candidate): void {
            $page = Page::query()->lockForUpdate()->find($candidate->page_id);
            $changed = PageReview::query()->whereKey($candidate->id)->where('status', 'pending')->where('expires_at', '<=', now()->timestamp)
                ->update(['status' => 'expired']);
            if ($changed && $page !== null) {
                app(ContentDependencies::class)->syncPage($page);
            }
        }, 3);
    }

    /** Recheck under the page lock so cancellation, rescheduling and overlapping scheduler runs are safe. */
    public function runDue(PageReview $candidate): bool
    {
        return DB::transaction(function () use ($candidate): bool {
            $page = Page::query()->lockForUpdate()->find($candidate->page_id);
            $review = PageReview::query()->lockForUpdate()->find($candidate->id);
            if ($review === null || $review->status !== 'scheduled' || $review->scheduled_for > now()->timestamp) {
                return true;
            }
            try {
                if ($page === null) {
                    throw new InvalidArgumentException('The page is unavailable.');
                }
                $actor = $this->publisher($review->publisher_guard, new ActorIdentity($review->publisher, $review->publisher_type));
                app(PageCommands::class)->publishReview($actor, $page->id, $review->id);
            } catch (AuthorizationException|ValidationException|InvalidArgumentException|ModelNotFoundException $error) {
                $message = $error instanceof AuthorizationException ? 'The scheduling publisher no longer has permission.' : $error->getMessage();
                $review->forceFill(['status' => 'failed', 'failure' => mb_substr($message, 0, 255)])->save();
                if ($page !== null) {
                    app(ContentDependencies::class)->syncPage($page);
                }

                return false;
            }

            return true;
        }, 3);
    }

    private function publisher(string $guard, ActorIdentity $expected): Authenticatable
    {
        $provider = config('auth.guards.'.$guard.'.provider');
        $actor = is_string($provider) ? Auth::createUserProvider($provider)?->retrieveById($expected->id) : null;
        if (! $actor instanceof Authenticatable || $actor::class !== $expected->type || (string) $actor->getAuthIdentifier() !== $expected->id) {
            throw new AuthorizationException('The publisher could not be verified through the selected authentication guard.');
        }

        return $actor;
    }
}
