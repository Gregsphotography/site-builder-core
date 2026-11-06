<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use SiteBuilder\Core\Composition\DocumentCopies;
use SiteBuilder\Core\Composition\StartingPointRegistry;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\Document;
use SiteBuilder\Core\Models\Page;
use SiteBuilder\Core\Persistence\PageCommands;
use SiteBuilder\Core\SiteParts\SitePart;
use Symfony\Component\Console\Output\BufferedOutput;

final class SetupManager
{
    public ?array $lastReceipt = null;

    private const STATE = 'storage/app/site-builder/setup/';

    public function __construct(private SetupRegistry $registry, private SetupFilesystem $fs, private SetupInspection $inspection, private CanonicalJson $json) {}

    public function installedAnswers(?string $profile = null): SetupAnswers
    {
        $stored = config('site-builder-setup.answers');
        if ($stored instanceof \stdClass) {
            $answers = new SetupAnswers($stored);
            if ($profile !== null && $profile !== $answers->get('profile')) {
                throw new SetupFailure('profile-conflict', 'The CLI and stored profiles disagree.');
            }

            return $answers;
        }
        $profile ??= 'custom';
        $value = (object) ['format' => 'laravel-site-builder/setup-answers', 'version' => 1, 'profile' => $profile, 'application' => 'existing',
            'locales' => json_decode(json_encode(config('site-builder.locales', ['en' => ['label' => 'English', 'dir' => 'ltr']]))),
            'routes' => (object) ['enabled' => (bool) config('site-builder.routes.enabled', true), 'prefix' => trim((string) config('site-builder.routes.prefix', 'builder'), '/'), 'previewPrefix' => trim((string) config('site-builder.routes.preview_prefix', '_site-builder/preview'), '/')],
            'presentation' => (object) ['mode' => 'host', 'layout' => config('site-builder.layout', 'site-builder::layouts.site')],
            'features' => (object) array_fill_keys(['media', 'savedSections', 'clipboard', 'history', 'siteParts', 'sampleDraft'], false),
            'authorization' => (object) ['mode' => 'existing', 'guard' => config('auth.defaults.guard', 'web'), 'actorAlias' => null, 'actorKeys' => [], 'pageAbilities' => [], 'sitePartAbilities' => (object) [], 'mediaAbilities' => []],
            'registration' => (object) ['mode' => 'manual', 'panelId' => null, 'panelFile' => null], 'storage' => (object) ['mode' => 'disabled', 'disk' => null, 'createLink' => false], 'migrations' => 'skip'];

        return new SetupAnswers($value);
    }

    private function discover(): array
    {
        $result = [];
        foreach ($this->registry->all() as $id => $contributor) {
            try {
                $result[$id] = $contributor->discover();
            } catch (SetupFailure $failure) {
                throw $failure;
            } catch (\Throwable) {
                throw new SetupFailure('discovery-unavailable', 'A selected installation capability could not be inspected. No connection or exception details are disclosed.', 2);
            }
        }

        return $result;
    }

    public function doctor(SetupAnswers $answers): array
    {
        [$checks] = $this->checks($answers, $this->discover());
        $report = ['format' => 'laravel-site-builder/doctor', 'version' => 1, 'profile' => $answers->get('profile'), 'status' => $this->readiness($checks), 'packages' => (object) $this->inspection->packages(), 'checks' => $checks];
        app(SetupArtifacts::class)->validate('doctor', json_decode(json_encode($report)));

        return $report;
    }

    private function checks(SetupAnswers $answers, array $discovery): array
    {
        $checks = [];
        foreach ($this->registry->all() as $id => $contributor) {
            $checks = [...$checks, ...$contributor->checks($answers, $discovery[$id])];
        }
        foreach (['components' => in_array($answers->get('profile'), ['public', 'editor'], true) || $answers->get('storage.mode') === 'local-public', 'filament' => $answers->get('registration.panelId') !== null || $answers->get('profile') === 'editor'] as $id => $required) {
            if (! isset($discovery[$id])) {
                $checks[] = new DoctorCheck('packages.'.$id, $required ? 'unavailable' : 'not-applicable', 'package-not-registered', 'The optional '.$id.' installation capability is not registered.', $required ? 'Install and discover the matching gregsphotography/site-builder-'.$id.' package with Composer before setup.' : null);
                foreach ($id === 'components' ? ['assets.components', 'assets.gallery', 'schema.media', 'storage.media', 'auth.media'] : ['assets.filament', 'panel.plugin'] as $checkId) {
                    $checks[] = $this->registry->check($checkId, $answers) ?? new DoctorCheck($checkId, $required ? 'unavailable' : ($answers->get('features.media') && in_array($checkId, ['storage.media', 'auth.media'], true) ? 'action' : 'not-applicable'), 'optional-capability', 'Optional capability readiness follows the selected installed adapter.', $required || $answers->get('features.media') ? 'Install the selected capability or verify the trusted host adapter.' : null);
                }
            }
        }
        $owned = $this->fs->owned();
        $changed = array_any($owned, fn ($hash, $path) => $this->fs->hash($path) !== $hash);
        $checks[] = new DoctorCheck('installation.ownership', $changed ? 'action' : 'pass', $changed ? 'owned-file-changed' : 'ownership-consistent', $changed ? 'A previously generated file was changed outside setup.' : 'Setup ownership hashes are consistent.', $changed ? 'Preserve the host change and review a manual merge.' : null);
        if ($answers->get('features.savedSections')) {
            $checks[] = $this->registry->check('auth.saved', $answers) ?? new DoctorCheck('auth.saved', 'action', 'saved-policy-required', 'Saved-section ownership/use rules remain host-owned.', 'Register and verify the existing saved-section policy/workflow.');
        }
        $ids = array_map(fn ($check) => $check->id, $checks);
        if (count($ids) !== count(array_unique($ids))) {
            throw new SetupFailure('duplicate-check', 'Setup contributors declared duplicate diagnostic IDs.', 2);
        }

        return [$checks, $owned];
    }

    public function plan(SetupAnswers $answers, bool $runMigrations = false): SetupPlan
    {
        if ($runMigrations && $answers->get('migrations') !== 'publish') {
            throw new SetupFailure('migrations-not-selected', 'Running migrations requires explicit migration publication selection.');
        }
        $discovery = $this->discover();
        [$checks, $owned] = $this->checks($answers, $discovery);
        $files = $sources = [];
        foreach ($this->registry->all() as $id => $contributor) {
            $contribution = $contributor->plan($answers, $discovery[$id]);
            $checks = [...$checks, ...$contribution->checks];
            foreach ($contribution->files as $file) {
                if (! $file instanceof SetupFile || isset($files[$file->path])) {
                    throw new SetupFailure('duplicate-path', 'Setup contributors declared duplicate or invalid file targets.', 3);
                }
                $this->fs->path($file->path);
                $files[$file->path] = [$id, $file];
            }
            $sources = [...$sources, ...$contribution->migrationSources];
        }
        $migrationPaths = [];
        foreach (app(MigrationPublisher::class)->plan($sources, $this->fs) as $file) {
            if (isset($files[$file->path])) {
                throw new SetupFailure('duplicate-path', 'A migration target is duplicated.', 3);
            }
            $files[$file->path] = ['core', $file];
            $migrationPaths[] = $file->path;
        }
        $operations = [];
        foreach ($files as $path => [$contributor, $file]) {
            $before = $this->fs->hash($path);
            $after = hash('sha256', $file->contents);
            $action = $before === $after ? 'unchanged' : ($before === null ? 'create' : (isset($owned[$path]) && $owned[$path] === $before ? 'replace-owned' : ($file->manualIfExisting ? 'manual' : 'conflict')));
            if (isset($owned[$path]) && $owned[$path] !== $before) {
                $action = 'conflict';
            }
            $operations[] = $this->operation('file-'.substr(hash('sha256', $path), 0, 24), $contributor, 'file', $action, $path, $before, $after, $file->summary, $file->redacted, $operations === [] ? [] : [$operations[array_key_last($operations)]['id']]);
        }
        $fileIds = $operations === [] ? [] : [$operations[array_key_last($operations)]['id']];
        if ($runMigrations) {
            $operations[] = $this->operation('run-migrations', 'core', 'migration', 'create', null, null, null, 'Run only selected reviewed package migrations, retaining framework production safeguards.', dependencies: $fileIds);
        }
        if ($answers->get('storage.createLink')) {
            $link = $this->fs->path('public/storage', true);
            $target = config('filesystems.disks.'.$answers->get('storage.disk').'.root');
            $valid = is_string($target) && realpath($target) !== false && str_starts_with(realpath($target).'/', $this->fs->root.'/');
            $existing = is_link($link) && realpath($link) === realpath((string) $target);
            $action = $existing ? 'unchanged' : (file_exists($link) || is_link($link) || ! $valid ? 'conflict' : 'create');
            $operations[] = $this->operation('storage-link', 'components', 'storage-link', $action, 'public/storage', null, null, 'Create only the explicitly selected safe local public storage link.', dependencies: $fileIds);
        }
        if ($answers->get('features.sampleDraft')) {
            $actor = $this->registry->sampleActor(answers: $answers);
            if ($actor === null || ! Gate::forUser($actor)->allows('create', Page::class)) {
                $checks[] = new DoctorCheck('sample.actor', 'unavailable', 'sample-actor-required', 'Sample creation requires a selected existing actor with actual create permission.', 'Complete authorization first or decline the sample draft.');
            }
            $operations[] = $this->operation('sample-draft', 'core', 'sample-draft', 'create', null, null, null, 'Create one authorized synthetic draft; never publish or overwrite content.', dependencies: $operations === [] ? [] : [$operations[array_key_last($operations)]['id']]);
        }
        if ($answers->get('authorization.mode') === 'allowlist') {
            $this->registry->selectedActors($answers);
            foreach ([Page::class, SitePart::class] as $model) {
                $policy = Gate::getPolicyFor($model);
                if ($policy !== null && ! $policy instanceof SetupPagePolicy && ! $policy instanceof SetupSitePartPolicy) {
                    $checks[] = new DoctorCheck('authorization.conflict', 'unavailable', 'policy-conflict', 'Allowlist generation cannot replace an existing host policy.', 'Choose existing-policy mode and complete manual integration.');
                    break;
                }
            }
        }
        $facts = $discovery['core']->facts;
        $inputFiles = array_diff_key($facts['files'], $files);
        $inputs = ['files' => $inputFiles, 'guards' => $facts['guards'], 'actors' => $facts['actors'], 'routes' => array_values(array_filter($facts['routes'], fn ($route) => ! $this->inspection->builderRoute($route))), 'databaseIdentity' => $facts['databaseIdentity'], 'sources' => $this->packageDigests(), 'packages' => $facts['packages']];
        $intent = array_map(fn ($op) => array_intersect_key($op, array_flip(['id', 'contributor', 'kind', 'path', 'afterHash', 'dependsOn'])), $operations);
        $unavailable = array_any($checks, fn ($check) => $check->status === 'unavailable');
        $blocked = array_any($operations, fn ($op) => $op['action'] === 'conflict') || array_any($checks, fn ($check) => $check->code === 'route-conflict');
        $report = ['format' => 'laravel-site-builder/setup-plan', 'version' => 1, 'profile' => $answers->get('profile'), 'status' => $unavailable ? 'unavailable' : ($blocked ? 'blocked' : 'ready-to-apply'), 'answersDigest' => $answers->digest(), 'discoveryDigest' => $this->json->digest([$inputs, $facts['schema'], array_column($operations, 'beforeHash', 'id')]), 'packages' => (object) $facts['packages'], 'operations' => $operations, 'checks' => $checks, 'nextActions' => $this->next($checks)];
        $report['digest'] = $this->json->digest($report);
        app(SetupArtifacts::class)->validate('setup-plan', json_decode(json_encode($report)));

        return new SetupPlan($report, $files, $migrationPaths, $inputs, $intent, $answers, $runMigrations);
    }

    private function operation(string $id, string $contributor, string $kind, string $action, ?string $path, ?string $before, ?string $after, string $summary, bool $redacted = false, array $dependencies = []): array
    {
        return ['id' => $id, 'contributor' => $contributor, 'kind' => $kind, 'action' => $action, 'path' => $path, 'beforeHash' => $before, 'afterHash' => $after, 'dependsOn' => $dependencies, 'summary' => $summary, 'redacted' => $redacted];
    }

    private function packageDigests(): array
    {
        $files = [];
        foreach (['core', 'components', 'filament'] as $name) {
            $package = 'gregsphotography/site-builder-'.$name;
            $directory = InstalledVersions::isInstalled($package) ? InstalledVersions::getInstallPath($package) : ($name === 'core' ? dirname(__DIR__, 2) : null);
            if (! is_string($directory)) {
                continue;
            }
            foreach (['src', 'resources', 'database'] as $folder) {
                if (! is_dir($directory.'/'.$folder)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory.'/'.$folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile()) {
                        $files[$name.'/'.substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
                    }
                }
            }
        }
        foreach ($this->registry->all() as $id => $contributor) {
            $file = (new \ReflectionClass($contributor))->getFileName();
            if (is_string($file)) {
                $files['contributor:'.$id] = hash_file('sha256', $file);
            }
        }
        ksort($files);

        return ['digest' => $this->json->digest($files)];
    }

    public function apply(SetupPlan $reviewed, ?string $acceptDigest = null, ?string $resume = null, bool $force = false): array
    {
        $this->lastReceipt = null;
        if ($resume !== null && ! Str::isUuid($resume)) {
            throw new SetupFailure('invalid-run', 'Resume requires a setup run UUID.');
        }
        if ($reviewed->runMigrations && app()->environment('production') && ! $force) {
            throw new SetupFailure('production-force-required', 'Production migration execution requires the explicit --force option.', 64);
        }
        $plan = $this->plan($reviewed->answers, $reviewed->runMigrations);
        if ($resume === null && ! hash_equals($plan->report['digest'], $acceptDigest ?? $reviewed->report['digest'])) {
            throw new SetupFailure('plan-changed', 'The reviewed plan changed. Inspect a new plan before applying.', 3);
        }
        if ($plan->report['status'] !== 'ready-to-apply' && $resume === null) {
            throw new SetupFailure('plan-blocked', 'The setup plan has blocking conflicts or unavailable capabilities.', $plan->report['status'] === 'unavailable' ? 2 : 1);
        }
        $lock = $this->fs->lock();
        try {
            // Re-read all preconditions after serializing concurrent setup processes.
            $current = $this->plan($reviewed->answers, $reviewed->runMigrations);
            if ($current->report['digest'] !== $plan->report['digest']) {
                throw new SetupFailure('plan-changed', 'Setup inputs changed while acquiring the apply lock.', 3);
            }
            $plan = $current;
            $owned = $this->fs->owned();
            $runId = $resume ?? (string) Str::uuid();
            if (! Str::isUuid($runId)) {
                throw new SetupFailure('invalid-run', 'Resume requires a setup run UUID.');
            }
            $receiptPath = self::STATE.'receipts/'.$runId.'.json';
            $runPath = self::STATE.'runs/'.$runId.'.json';
            $schema = $this->inspection->core()->facts['schema'];
            if ($resume !== null) {
                $receipt = app(SetupArtifacts::class)->decode('setup-receipt', $this->fs->read($receiptPath) ?? '');
                $state = json_decode($this->fs->read($runPath) ?? '{}', true, 64, JSON_THROW_ON_ERROR);
                if (($state['version'] ?? null) !== 1 || ($state['planDigest'] ?? null) !== $receipt->planDigest || $receipt->answersDigest !== $plan->answers->digest() || ($state['inputsDigest'] ?? null) !== $this->json->digest($plan->inputs) || ($state['intentDigest'] ?? null) !== $this->json->digest($plan->intent) || ($state['schemaDigest'] ?? null) !== $this->json->digest($schema)) {
                    throw new SetupFailure('resume-changed', 'Answers, packages, host inputs, selected operations or migration state changed. Review a new plan.', 3);
                }
                $report = json_decode(json_encode($receipt), true);
                if (array_column($report['outcomes'], 'id') !== array_column($plan->report['operations'], 'id') || ($acceptDigest !== null && ! hash_equals($report['planDigest'], $acceptDigest))) {
                    throw new SetupFailure('resume-changed', 'The reviewed resume plan or journal does not match.', 3);
                }
                foreach ($report['outcomes'] as $outcome) {
                    if (! in_array($outcome['status'], ['applied', 'unchanged'], true) || $outcome['path'] === null) {
                        continue;
                    }
                    if ($outcome['path'] === 'public/storage') {
                        $link = $this->fs->path('public/storage', true);
                        $target = config('filesystems.disks.'.$plan->answers->get('storage.disk').'.root');
                        if (! is_link($link) || ! is_string($target) || realpath($link) !== realpath($target)) {
                            throw new SetupFailure('resume-link-changed', 'The completed storage link changed.', 3);
                        }
                    } elseif ($this->fs->hash($outcome['path']) !== $outcome['afterHash']) {
                        throw new SetupFailure('resume-file-changed', 'A completed setup-owned file changed. Preserve it and review a manual merge.', 3);
                    }
                }
            } else {
                $report = ['format' => 'laravel-site-builder/setup-receipt', 'version' => 1, 'runId' => $runId, 'planDigest' => $plan->report['digest'], 'answersDigest' => $plan->answers->digest(), 'status' => 'partial', 'readiness' => 'needs-action', 'packages' => $plan->report['packages'], 'outcomes' => array_map(fn ($op) => ['id' => $op['id'], 'status' => 'pending', 'path' => $op['path'], 'beforeHash' => $op['beforeHash'], 'afterHash' => $op['afterHash'], 'code' => 'planned'], $plan->report['operations']), 'nextActions' => []];
                $state = ['version' => 1, 'planDigest' => $report['planDigest'], 'inputsDigest' => $this->json->digest($plan->inputs), 'intentDigest' => $this->json->digest($plan->intent), 'schemaDigest' => $this->json->digest($schema)];
                $this->fs->state($runPath, $state);
                $this->saveReceipt($receiptPath, $report);
            }
            foreach ($plan->report['operations'] as $index => $op) {
                if (in_array($report['outcomes'][$index]['status'] ?? null, ['applied', 'unchanged'], true)) {
                    continue;
                }
                try {
                    if ($op['action'] === 'conflict') {
                        throw new SetupFailure('file-conflict', 'An unowned or changed destination requires manual review.', 3);
                    }
                    if ($op['kind'] === 'file') {
                        [, $file] = $plan->files[$op['path']];
                        $actual = $this->fs->hash($file->path);
                        if ($op['action'] === 'manual') {
                            $code = 'manual-integration';
                            $status = 'unchanged';
                        } elseif ($actual === $op['afterHash']) {
                            $code = 'file-matches';
                            $status = 'unchanged';
                        } else {
                            $this->fs->write($file->path, $file->contents, $op['beforeHash'], $file->redacted ? 0600 : 0644);
                            $owned[$file->path] = $op['afterHash'];
                            $code = 'file-written';
                            $status = 'applied';
                        }
                        $after = $this->fs->hash($file->path);
                        $this->fs->state(self::STATE.'owned-v1.json', ['format' => 'laravel-site-builder/setup-owned', 'version' => 1, 'stubs' => array_fill_keys(array_keys($this->registry->all()), 1), 'files' => $owned]);
                    } elseif ($op['kind'] === 'migration') {
                        $output = new BufferedOutput;
                        $exit = Artisan::call('migrate', ['--path' => $plan->migrationPaths, '--force' => $force, '--no-interaction' => true], $output);
                        if ($exit !== 0) {
                            throw new SetupFailure('migration-failed', 'Selected migrations did not finish. Inspect local application logs; completed work is retained.', 70);
                        }
                        $code = 'migrations-finished';
                        $status = 'applied';
                        $after = null;
                    } elseif ($op['kind'] === 'storage-link') {
                        $link = $this->fs->path('public/storage', true);
                        $target = config('filesystems.disks.'.$plan->answers->get('storage.disk').'.root');
                        if (is_link($link) && realpath($link) === realpath($target)) {
                            $status = 'unchanged';
                        } elseif (! file_exists($link) && ! is_link($link) && is_string($target) && realpath($target) !== false && str_starts_with(realpath($target).'/', $this->fs->root.'/') && symlink(realpath($target), $link)) {
                            $status = 'applied';
                        } else {
                            throw new SetupFailure('storage-link-conflict', 'The reviewed storage link cannot be safely created.', 3);
                        }
                        $code = 'storage-linked';
                        $after = null;
                    } else {
                        $this->sample($plan->answers);
                        $code = 'sample-ready';
                        $status = 'applied';
                        $after = null;
                    }
                    $report['outcomes'][$index] = ['id' => $op['id'], 'status' => $status, 'path' => $op['path'], 'beforeHash' => $op['beforeHash'], 'afterHash' => $after, 'code' => $code];
                    $report['status'] = array_all($report['outcomes'], fn ($outcome) => in_array($outcome['status'], ['applied', 'unchanged'], true)) ? 'complete' : 'partial';
                    $state['schemaDigest'] = $this->json->digest($this->inspection->core()->facts['schema']);
                    $this->fs->state($runPath, $state);
                    $this->saveReceipt($receiptPath, $report);
                } catch (\Throwable $exception) {
                    $code = $exception instanceof SetupFailure ? $exception->reason : 'operation-failed';
                    $report['outcomes'][$index]['status'] = 'failed';
                    $report['outcomes'][$index]['code'] = $code;
                    $report['status'] = 'partial';
                    $report['nextActions'] = [['code' => 'resume', 'message' => 'Inspect the failed local operation and resume this same reviewed run; completed work is retained.']];
                    $state['schemaDigest'] = $this->json->digest($this->inspection->core()->facts['schema']);
                    $this->fs->state($runPath, $state);
                    $this->saveReceipt($receiptPath, $report);
                    throw new SetupFailure('apply-partial', 'Setup stopped with a partial receipt. No destructive rollback was attempted.', 70);
                }
            }
            $report['status'] = 'complete';
            $doctor = $this->doctor($plan->answers);
            $report['readiness'] = $doctor['status'];
            $report['nextActions'] = $this->next($doctor['checks']);
            $this->saveReceipt($receiptPath, $report);

            return $report;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function sample(SetupAnswers $answers): void
    {
        $marker = self::STATE.'samples/'.$answers->digest().'.json';
        if (($raw = $this->fs->read($marker)) !== null) {
            $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (($state['status'] ?? null) === 'complete' && Page::query()->whereKey($state['pageId'] ?? 0)->exists()) {
                return;
            }
            throw new SetupFailure('sample-uncertain', 'Sample completion is uncertain or its owner was removed. Review the existing setup draft instead of creating a duplicate.', 3);
        }
        $actor = $this->registry->sampleActor(answers: $answers);
        if ($actor === null) {
            throw new SetupFailure('sample-actor-unavailable', 'The selected sample actor is unavailable.', 3);
        }
        Gate::forUser($actor)->authorize('create', Page::class);
        $this->fs->state($marker, ['version' => 1, 'status' => 'pending']);
        $document = new Document;
        $sources = app(StartingPointRegistry::class);
        if ($sources->find('component:standard.hero') !== null) {
            $document = app(DocumentCopies::class)->copy($sources->snapshot('component:standard.hero', 1, array_key_first((array) $answers->get('locales')))->document);
        }
        $metadata = ['title' => 'Site Builder example draft'];
        if ($answers->get('presentation.layout') !== 'site-builder::layouts.site') {
            $metadata['layout'] = $answers->get('presentation.layout');
        }
        $page = DB::transaction(function () use ($actor, $metadata, $document): Page {
            $page = app(PageCommands::class)->create($actor, $metadata, [], $document)->page;
            Gate::forUser($actor)->authorize('update', $page);

            return $page;
        });
        $this->fs->state($marker, ['version' => 1, 'status' => 'complete', 'pageId' => $page->id]);
    }

    private function saveReceipt(string $path, array $report): void
    {
        app(SetupArtifacts::class)->validate('setup-receipt', json_decode(json_encode($report)));
        $this->fs->state($path, $report);
        $this->lastReceipt = $report;
    }

    private function next(array $checks): array
    {
        return array_values(array_map(fn ($check) => ['code' => $check->code, 'message' => $check->action ?? $check->message], array_filter($checks, fn ($check) => in_array($check->status, ['action', 'unavailable'], true))));
    }

    private function readiness(array $checks): string
    {
        return array_any($checks, fn ($check) => $check->status === 'unavailable') ? 'unavailable' : (array_any($checks, fn ($check) => $check->status === 'action') ? 'needs-action' : 'ready');
    }
}
