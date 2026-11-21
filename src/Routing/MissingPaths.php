<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Routing;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SiteBuilder\Core\Models\PageRedirect;
use SiteBuilder\Core\Models\PageSlug;

final class MissingPaths
{
    public function available(): bool
    {
        return Schema::hasTable('site_builder_missing_paths') && Schema::hasTable('site_builder_route_locks');
    }

    public function record(Request $request, string $locale, string $slug): void
    {
        if (! config('site-builder.missing_paths.enabled', true) || ! $request->isMethod('GET') || $request->expectsJson()
            || $request->query->has('signature') || ! RedirectPath::usable($slug) || ! $this->recordable($slug)) {
            return;
        }
        try {
            if (! $this->available()) {
                return;
            }
            DB::transaction(function () use ($locale, $slug): void {
                $this->lock();
                $where = ['locale' => $locale, 'slug' => $slug];
                if (PageSlug::query()->where($where)->exists() || PageRedirect::query()->where(['locale' => $locale, 'from_slug' => $slug])->exists()) {
                    return;
                }
                $retained = $this->pruneLocked();
                $table = DB::table('site_builder_missing_paths');
                $row = (clone $table)->where($where)->first();
                if ($row !== null) {
                    $table->where('id', $row->id)->update(['hits' => min(2147483647, $row->hits + 1), 'last_seen_at' => now()]);
                } elseif ($retained < $this->limit()) {
                    $table->insert([...$where, 'hits' => 1, 'last_seen_at' => now()]);
                }
            }, 3);
        } catch (QueryException) {
            // Optional reporting must not turn a missing page into a server error.
        }
    }

    public function prune(): void
    {
        DB::transaction(function (): void {
            $this->lock();
            $this->pruneLocked();
        }, 3);
    }

    private function limit(): int
    {
        return max(1, min(10000, (int) config('site-builder.missing_paths.max_paths', 1000)));
    }

    private function pruneLocked(): int
    {
        $days = max(1, min(90, (int) config('site-builder.missing_paths.retention_days', 30)));
        $table = DB::table('site_builder_missing_paths');
        (clone $table)->where('last_seen_at', '<', now()->subDays($days))->delete();
        $count = $table->count();
        if ($count > $this->limit()) {
            $obsolete = (clone $table)->orderBy('last_seen_at')->orderBy('id')->limit($count - $this->limit())->pluck('id');
            foreach ($obsolete->chunk(250) as $ids) {
                (clone $table)->whereIn('id', $ids)->delete();
            }

            return $this->limit();
        }

        return $count;
    }

    public function forget(string $locale, string $slug): void
    {
        if ($this->available()) {
            $this->lock();
            DB::table('site_builder_missing_paths')->where(compact('locale', 'slug'))->delete();
        }
    }

    private function lock(): void
    {
        DB::table('site_builder_route_locks')->where('name', 'missing-paths')->lockForUpdate()->first();
    }

    private function recordable(string $slug): bool
    {
        // Omit private endpoints, asset/probe paths and likely identifiers or tokens.
        foreach (explode('/', strtolower($slug)) as $segment) {
            if (str_starts_with($segment, '_')
                || in_array($segment, ['admin', 'api', 'auth', 'oauth', 'login', 'logout', 'password', 'forgot-password', 'reset-password', 'password-reset', 'reset', 'passwordless', 'magic-link', 'callback', 'unsubscribe', 'signed', 'register', 'token', 'tokens', 'invite', 'invitations', 'verify-email', 'verification', 'account', 'accounts', 'livewire', 'storage', 'vendor', 'assets', 'wp-admin', 'wp-content', 'wp-login.php', 'xmlrpc.php'], true)
                || preg_match('/^[\da-f-]{20,}$|[a-z\d_-]{48,}|\d{7,}/i', $segment)) {
                return false;
            }
        }

        return true;
    }
}
