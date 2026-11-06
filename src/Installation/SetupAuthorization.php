<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/** Explicit builder-only grants. No global Gate hook, account mutation or import authority. */
final class SetupAuthorization
{
    public function __construct(private SetupRegistry $registry) {}

    public function allows(Authenticatable $actor, string $ability, string $domain, ?string $kind = null): bool
    {
        if (! app()->bound('site-builder.setup.active')) {
            return false;
        }
        $value = config('site-builder-setup.answers');
        if (! $value instanceof \stdClass) {
            return false;
        }
        $answers = new SetupAnswers($value);
        if ($answers->get('authorization.mode') !== 'allowlist') {
            return false;
        }
        if (($domain === 'media' && ! $answers->get('features.media')) || ($domain === 'site-part' && ! $answers->get('features.siteParts'))) {
            return false;
        }
        $catalog = $this->registry->actors()[$answers->get('authorization.actorAlias')] ?? null;
        if ($catalog === null || $catalog['guard'] !== $answers->get('authorization.guard') || $actor::class !== $catalog['model'] || ! in_array((string) $actor->getAuthIdentifier(), $answers->get('authorization.actorKeys'), true)) {
            return false;
        }
        if (! app()->runningInConsole()) {
            $authenticated = Auth::guard($catalog['guard'])->user();
            if ($authenticated === null || $authenticated::class !== $actor::class || (string) $authenticated->getAuthIdentifier() !== (string) $actor->getAuthIdentifier()) {
                return false;
            }
        }
        $abilities = match ($domain) {
            'page' => $answers->get('authorization.pageAbilities'),
            'media' => $answers->get('authorization.mediaAbilities'),
            'site-part' => data_get($answers->get('authorization.sitePartAbilities'), $kind === 'design' ? 'site-design' : $kind, []),
            default => [],
        };

        return in_array($ability, $abilities, true);
    }

    public function media(Authenticatable $actor, string $ability): ?bool
    {
        $mode = data_get(config('site-builder-setup.answers'), 'authorization.mode');

        return app()->bound('site-builder.setup.active') && in_array($mode, ['allowlist', 'deny'], true) ? $this->allows($actor, $ability, 'media') : null;
    }
}
