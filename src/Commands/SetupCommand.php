<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use SiteBuilder\Core\Installation\SetupAnswers;
use SiteBuilder\Core\Installation\SetupFailure;
use SiteBuilder\Core\Installation\SetupManager;
use SiteBuilder\Core\Installation\SetupPlan;
use SiteBuilder\Core\Installation\SetupRegistry;
use SiteBuilder\Core\Rendering\LayoutRegistry;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SetupCommand extends Command
{
    protected $signature = 'site-builder:setup
        {--profile= : public, editor or custom}
        {--answers= : Application-relative complete v1 answers JSON}
        {--plan : Read-only discovery and reviewed plan}
        {--json : Emit only a versioned JSON artifact}
        {--apply : Explicitly apply the selected plan}
        {--accept-plan= : Require this reviewed plan SHA-256}
        {--run-migrations : Explicitly run only selected package migrations}
        {--resume= : Resume a recorded run UUID}
        {--force : Explicitly permit selected migrations in production}';

    protected $description = 'Review and apply additive, explicit Site Builder setup';

    public function handle(SetupManager $manager): int
    {
        try {
            if ($this->option('plan') && $this->option('apply')) {
                throw new SetupFailure('conflicting-mode', '--plan and --apply are mutually exclusive.');
            }
            if (! $this->option('apply') && ($this->option('run-migrations') || $this->option('resume') || $this->option('accept-plan') || $this->option('force'))) {
                throw new SetupFailure('apply-required', 'Migration execution, resume, force and plan acceptance require --apply.');
            }
            if ($this->option('accept-plan') !== null && ! preg_match('/^[a-f0-9]{64}$/D', $this->option('accept-plan'))) {
                throw new SetupFailure('invalid-plan-digest', 'Plan acceptance requires a SHA-256 digest.');
            }
            if ($this->option('resume') !== null && ! Str::isUuid($this->option('resume'))) {
                throw new SetupFailure('invalid-run', 'Resume requires a setup run UUID.');
            }
            $profile = $this->option('profile');
            if ($profile !== null && ! in_array($profile, ['public', 'editor', 'custom'], true)) {
                throw new SetupFailure('invalid-profile', 'Select public, editor or custom.');
            }
            $answers = $this->option('answers') !== null ? $this->readAnswers($this->option('answers')) : $this->wizard($manager, $profile);
            if ($profile !== null && $profile !== $answers->get('profile')) {
                throw new SetupFailure('profile-conflict', 'The CLI and answers profiles disagree.');
            }
            $plan = $manager->plan($answers, (bool) $this->option('run-migrations'));
            if (! $this->option('apply')) {
                $this->renderPlan($plan);
                if ($this->option('plan') || ! $this->input->isInteractive() || $this->option('json')) {
                    return $this->planExit($plan);
                }
                if ($plan->report['status'] !== 'ready-to-apply' || ! $this->confirm('Apply exactly this plan? Existing host integration steps remain explicit.', false)) {
                    return $this->planExit($plan);
                }
            }
            $receipt = $manager->apply($plan, $this->option('accept-plan'), $this->option('resume'), (bool) $this->option('force'));
            $this->renderReport($receipt);

            return match ($receipt['readiness']) {
                'ready' => 0, 'unavailable' => 2, default => 1
            };
        } catch (SetupFailure $failure) {
            if ($failure->exitStatus === 70 && $manager->lastReceipt !== null) {
                $this->renderReport($manager->lastReceipt);
            } else {
                $this->writeError($failure->reason.': '.$failure->getMessage());
            }

            return $failure->exitStatus;
        } catch (\Throwable) {
            $this->writeError('setup-unavailable: Setup could not inspect or apply a capability. Inspect local logs; no connection or exception details are disclosed.');

            return 2;
        }
    }

    public function readAnswers(string $path): SetupAnswers
    {
        if (strlen($path) > 255 || ! preg_match('~^[A-Za-z0-9_./-]+\.json$~D', $path) || array_any(explode('/', $path), fn ($part) => $part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.'))) {
            throw new SetupFailure('unsafe-answers-path', 'Answers must be a normalized application-relative JSON file.');
        }
        $current = base_path();
        foreach (explode('/', $path) as $part) {
            $current .= '/'.$part;
            if (is_link($current)) {
                throw new SetupFailure('unsafe-answers-path', 'Answers must not traverse symbolic links.');
            }
        }
        if (! is_file($current) || filesize($current) > 524288) {
            throw new SetupFailure('answers-unavailable', 'The bounded application-relative answers file is unavailable.');
        }

        return SetupAnswers::fromJson(file_get_contents($current));
    }

    private function wizard(SetupManager $manager, ?string $profile): SetupAnswers
    {
        if (! $this->input->isInteractive() || $this->option('json')) {
            throw new SetupFailure('answers-required', 'Provide a complete v1 --answers document for unattended or JSON setup.');
        }
        $profile ??= $this->choice('Installation profile', ['public', 'editor', 'custom'], 'public');
        $value = $manager->installedAnswers()->jsonSerialize();
        $value->profile = $profile;
        $value->application = $this->choice('Application mode', ['existing', 'fresh'], 'existing');
        $value->locales = new \stdClass;
        foreach (array_unique(array_map('trim', explode(',', (string) $this->ask('Content locale codes, comma separated', 'en')))) as $locale) {
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,11}$/D', $locale)) {
                throw new SetupFailure('invalid-locale', 'Use bounded locale codes.');
            }
            $value->locales->{$locale} = (object) ['label' => $this->ask('Label for '.$locale, $locale), 'dir' => $this->choice('Direction for '.$locale, ['ltr', 'rtl'], 'ltr')];
        }
        $value->routes->enabled = $this->confirm('Enable public pages and signed preview routes?', true);
        $prefix = trim((string) $this->ask('Public route prefix (blank explicitly owns root routing)', 'builder'), '/');
        if ($prefix === '' && ! $this->confirm('Review root route ownership and all conflicts explicitly?', false)) {
            throw new SetupFailure('root-not-confirmed', 'Root routing was not confirmed.');
        }
        $value->routes->prefix = $prefix;
        $value->routes->previewPrefix = trim((string) $this->ask('Signed preview prefix', '_site-builder/preview'), '/');
        $layouts = app(LayoutRegistry::class)->keys();
        $layouts[] = 'site-builder::layouts.site';
        $layout = $this->choice('Trusted registered layout', array_values(array_unique($layouts)), in_array('components.minimal', $layouts, true) ? 'components.minimal' : 'site-builder::layouts.site');
        $value->presentation = (object) ['mode' => in_array($layout, ['components.minimal', 'components.business', 'components.editorial'], true) ? 'preset' : 'host', 'layout' => $layout];
        foreach (['media', 'savedSections', 'clipboard', 'history', 'siteParts', 'sampleDraft'] as $feature) {
            $value->features->{$feature} = $this->confirm('Enable '.$feature.'?', false);
        }
        $mode = $this->choice('Authorization integration (no accounts or roles are created)', ['existing', 'deny', 'allowlist'], 'existing');
        $guards = array_keys(config('auth.guards', []));
        $guard = $this->choice('Existing authentication guard', $guards, config('auth.defaults.guard'));
        $value->authorization = (object) ['mode' => $mode, 'guard' => $guard, 'actorAlias' => null, 'actorKeys' => [], 'pageAbilities' => [], 'sitePartAbilities' => new \stdClass, 'mediaAbilities' => []];
        if ($mode === 'allowlist') {
            $catalog = array_filter(app(SetupRegistry::class)->actors(), fn ($entry) => $entry['guard'] === $guard);
            if ($catalog === []) {
                throw new SetupFailure('actor-alias-unavailable', 'Register an existing Eloquent actor alias for the selected guard first.', 2);
            }
            $value->authorization->actorAlias = $this->choice('Trusted actor alias', array_keys($catalog));
            $value->authorization->actorKeys = array_values(array_unique(array_map('trim', explode(',', (string) $this->ask('Explicit existing opaque actor keys, comma separated')))));
            $value->authorization->pageAbilities = $this->abilities('Page', ['view', 'create', 'update', 'preview', 'publish', 'restore', 'delete']);
            if ($value->features->siteParts) {
                foreach (['section', 'header', 'footer', 'site-design', 'overlay'] as $kind) {
                    if ($this->confirm('Grant selected actors any '.$kind.' abilities?', false)) {
                        $value->authorization->sitePartAbilities->{$kind} = $this->abilities($kind, ['view', 'create', 'update', 'preview', 'publish', 'restore', 'delete', 'use', 'withdraw']);
                    }
                }
            }
            if ($value->features->media) {
                $value->authorization->mediaAbilities = $this->abilities('Media (separate from Pages)', ['view', 'upload', 'update', 'delete']);
            }
            $this->line('Review selected actor keys: '.implode(', ', $value->authorization->actorKeys));
            if (! $this->confirm('Grant exactly these selected abilities to these existing actors?', false)) {
                throw new SetupFailure('grants-not-confirmed', 'Explicit grants were not confirmed.');
            }
        }
        $value->registration->mode = $this->choice('Runtime integration', ['provider-stub', 'manual'], 'provider-stub');
        $editor = $profile === 'editor' || ($profile === 'custom' && isset(app(SetupRegistry::class)->all()['filament']) && $this->confirm('Integrate an already installed Filament panel?', false));
        $value->registration->panelId = $editor ? $this->ask('Existing Filament panel ID', 'admin') : null;
        $value->registration->panelFile = $value->registration->panelId === null ? null : $this->ask('Existing panel provider path', 'app/Providers/Filament/AdminPanelProvider.php');
        $storage = $value->features->media ? $this->choice('Media adapter', ['local-public', 'host'], 'local-public') : 'disabled';
        $value->storage = (object) ['mode' => $storage, 'disk' => $storage === 'disabled' ? null : $this->ask('Configured storage disk', 'public'), 'createLink' => $storage === 'local-public' && $this->confirm('Create the reviewed public/storage link?', false)];
        $value->migrations = $this->choice('Package migrations (execution requires --apply --run-migrations)', ['publish', 'skip'], 'publish');

        return new SetupAnswers($value);
    }

    private function abilities(string $label, array $allowed): array
    {
        $this->line($label.' abilities: '.implode(', ', $allowed));
        $raw = trim((string) $this->ask('Comma separated explicit abilities; blank grants none', ''));
        $selected = $raw === '' ? [] : array_values(array_unique(array_map('trim', explode(',', $raw))));
        if (array_diff($selected, $allowed) !== []) {
            throw new SetupFailure('invalid-abilities', 'Choose only declared abilities.');
        }

        return $selected;
    }

    private function planExit(SetupPlan $plan): int
    {
        return match ($plan->report['status']) {
            'ready-to-apply' => 0, 'unavailable' => 2, default => 1
        };
    }

    private function renderPlan(SetupPlan $plan): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->line('Plan '.$plan->report['digest'].' — '.$plan->report['status']);
        foreach ($plan->report['operations'] as $operation) {
            $this->line($operation['action'].' '.($operation['path'] ?? $operation['id']).' — '.$operation['summary']);
        }
        foreach ($plan->report['nextActions'] as $action) {
            $this->line($action['code'].': '.$action['message']);
        }
    }

    private function renderReport(array $report): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->line('Run '.$report['runId'].' — '.$report['status'].'; '.$report['readiness']);
        foreach ($report['nextActions'] as $action) {
            $this->line($action['code'].': '.$action['message']);
        }
    }

    private function writeError(string $message): void
    {
        $output = $this->output->getOutput();
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        $output->writeln($message, OutputInterface::OUTPUT_RAW);
    }
}
