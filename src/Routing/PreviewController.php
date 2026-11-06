<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Rendering\LocaleCatalog;
use SiteBuilder\Core\Rendering\PageResponse;

final class PreviewController
{
    public function __invoke(Request $request, string $page, string $locale, PageResponse $response, LocaleCatalog $locales): Response
    {
        $epoch = (string) config('site-builder.preview.epoch', '1');
        if ($epoch === '' || ! is_string($request->query('epoch')) || ! hash_equals($epoch, $request->query('epoch')) || ! $request->query('expires') || ! $request->hasValidRelativeSignature()) {
            return response('Invalid or expired preview.', 403, PageResponse::privateHeaders());
        }
        $record = ctype_digit($page) ? Page::query()->find($page) : null;
        if ($record === null || ! $locales->has($locale)) {
            return response('Preview not found.', 404, PageResponse::privateHeaders());
        }

        try {
            $editor = null;
            if ($request->has('session') || $request->has('channel')) {
                if (! Str::isUuid((string) $request->query('session')) || ! Str::isUuid((string) $request->query('channel'))) {
                    return response('Invalid preview context.', 403, PageResponse::privateHeaders());
                }
                $editor = ['protocol' => 'site-builder.preview', 'version' => 1, 'pageId' => (int) $record->id, 'locale' => $locale, 'session' => $request->query('session'), 'channel' => $request->query('channel')];
            }

            return $response->render($record, $locale, true, $editor);
        } catch (\Throwable $exception) {
            report($exception);

            return response('Preview could not be rendered.', 500, PageResponse::privateHeaders());
        }
    }
}
