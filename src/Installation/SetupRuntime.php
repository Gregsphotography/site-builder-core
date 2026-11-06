<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Illuminate\Support\Facades\Gate;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\SiteParts\SitePart;

/** Host-invoked runtime wiring only. No discovery, setup writes, users, schema or content. */
final class SetupRuntime
{
    public function boot(): void
    {
        if (app()->bound('site-builder.setup.active')) {
            return;
        }
        $answers = new SetupAnswers(config('site-builder-setup.answers') ?? throw new SetupFailure('setup-config-missing', 'Complete the reviewed setup configuration before registering its provider.'));
        $mode = $answers->get('authorization.mode');
        if (in_array($mode, ['allowlist', 'deny'], true)) {
            $namespace = app()->getNamespace();
            $bindings = [Page::class => $namespace.'Policies\\SiteBuilder\\PagePolicy', SitePart::class => $namespace.'Policies\\SiteBuilder\\SitePartPolicy'];
            foreach ($bindings as $model => $policy) {
                $current = Gate::getPolicyFor($model);
                if ($mode === 'allowlist' && $current !== null && ! $current instanceof SetupPagePolicy && ! $current instanceof SetupSitePartPolicy) {
                    throw new SetupFailure('policy-conflict', 'An existing host policy remains authoritative; complete manual policy integration.', 3);
                }
                if ($current === null && ! class_exists($policy)) {
                    throw new SetupFailure('policy-stub-missing', 'The reviewed generated policy is unavailable.', 3);
                }
            }
            foreach ($bindings as $model => $policy) {
                if (Gate::getPolicyFor($model) === null) {
                    Gate::policy($model, $policy);
                }
            }
        }
        app()->instance('site-builder.setup.active', true);
    }
}
