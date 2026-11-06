<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class SetupRegistry
{
    private array $contributors = [];

    private array $actors = [];

    private array $probes = [];

    private ?\Closure $sampleActor = null;

    public function register(SetupContributor $contributor): void
    {
        $id = $contributor->id();
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) || isset($this->contributors[$id])) {
            throw new \InvalidArgumentException('Setup contributor IDs must be unique.');
        }
        $this->contributors[$id] = $contributor;
    }

    public function all(): array
    {
        return $this->contributors;
    }

    public function actor(string $alias, string $guard, string $model): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $alias) || isset($this->actors[$alias]) || ! is_subclass_of($model, Model::class) || ! is_subclass_of($model, Authenticatable::class)) {
            throw new \InvalidArgumentException('Register a unique trusted Eloquent actor alias.');
        }
        $this->actors[$alias] = ['guard' => $guard, 'model' => $model];
    }

    public function actors(): array
    {
        $actors = $this->actors;
        foreach (config('auth.guards', []) as $guard => $definition) {
            $model = config('auth.providers.'.($definition['provider'] ?? '').'.model');
            if (! isset($actors[$guard]) && is_string($model) && is_subclass_of($model, Model::class) && is_subclass_of($model, Authenticatable::class)) {
                $actors[$guard] = ['guard' => $guard, 'model' => $model];
            }
        }

        return $actors;
    }

    public function selectedActors(SetupAnswers $answers): array
    {
        if ($answers->get('authorization.mode') !== 'allowlist') {
            return [];
        }
        $entry = $this->actors()[$answers->get('authorization.actorAlias')] ?? null;
        if ($entry === null || $entry['guard'] !== $answers->get('authorization.guard')) {
            throw new SetupFailure('actor-alias-unavailable', 'Choose an existing trusted actor alias for the selected guard.', 2);
        }
        $actors = [];
        foreach ($answers->get('authorization.actorKeys') as $key) {
            $actor = $entry['model']::query()->find($key);
            if (! $actor instanceof Authenticatable || (string) $actor->getAuthIdentifier() !== $key) {
                throw new SetupFailure('actor-unavailable', 'A selected actor is missing or does not match its opaque key.', 2);
            }
            $actors[] = $actor;
        }

        return $actors;
    }

    /** Existing policies needing application context get a host-owned read-only workflow probe. */
    public function probe(string $id, \Closure $probe): void
    {
        $this->probes[$id] = $probe;
    }

    public function check(string $id, SetupAnswers $answers): ?DoctorCheck
    {
        return isset($this->probes[$id]) ? ($this->probes[$id])($answers) : null;
    }

    public function sampleActor(?\Closure $resolver = null, ?SetupAnswers $answers = null): ?Authenticatable
    {
        if ($resolver !== null) {
            $this->sampleActor = $resolver;

            return null;
        }

        return $answers === null ? null : ($this->selectedActors($answers)[0] ?? ($this->sampleActor === null ? null : ($this->sampleActor)($answers)));
    }
}
