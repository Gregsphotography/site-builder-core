<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

final class SafeUrl
{
    public function canonical(string $url): bool
    {
        return $this->valid($url) && ($url === '' || str_starts_with($url, '/') || preg_match('~^https?://~i', $url) === 1);
    }

    public function valid(string $url, bool $media = false): bool
    {
        $decoded = html_entity_decode(rawurldecode($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/[\x00-\x20\x7f\\\\]/u', $url) || preg_match('/[\x00-\x1f\x7f\\\\]/u', $decoded) || str_starts_with($decoded, '//')) {
            return false;
        }
        if ($url === '') {
            return true;
        }
        if (str_starts_with($decoded, '/') || (! $media && str_starts_with($decoded, '#'))) {
            return ! preg_match('~(?:^|/)\.\.(?:/|$)~', $decoded);
        }
        $scheme = strtolower((string) parse_url($decoded, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($decoded, PHP_URL_USER) === null;
        }
        if (! $media && in_array($scheme, ['mailto', 'tel'], true)) {
            return strlen($decoded) > strlen($scheme) + 1;
        }

        return $media && preg_match('~^[\pL\pN_./%-]+$~uD', $url) === 1 && ! preg_match('~(?:^|/)\.\.(?:/|$)~', $decoded);
    }
}
