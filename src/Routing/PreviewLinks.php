<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageAccess;
use SiteBuilder\Core\Rendering\LocaleCatalog;

final class PreviewLinks
{
    public function __construct(private PageAccess $access, private LocaleCatalog $locales) {}

    public function create(Authenticatable $actor, int $pageId, string $locale, ?string $session = null, ?string $channel = null): string
    {
        $page = Page::query()->findOrFail($pageId);
        $this->access->authorize($actor, 'preview', $page);
        if (! $this->locales->has($locale)) {
            throw new InvalidArgumentException('Unknown preview locale.');
        }

        if (($session !== null || $channel !== null) && (! Str::isUuid($session ?? '') || ! Str::isUuid($channel ?? ''))) {
            throw new InvalidArgumentException('Editor previews require a UUID session and channel.');
        }

        $epoch = (string) config('site-builder.preview.epoch', '1');
        if ($epoch === '') {
            throw new InvalidArgumentException('Configure a nonempty preview epoch.');
        }

        return URL::temporarySignedRoute('site-builder.preview', now()->addMinutes(max(1, (int) config('site-builder.preview.expiry_minutes', 1440))), ['page' => $pageId, 'locale' => $locale, 'epoch' => $epoch, 'session' => $session, 'channel' => $channel], absolute: false);
    }
}
