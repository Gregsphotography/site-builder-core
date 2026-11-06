<?php

declare(strict_types=1);

namespace SiteBuilder\Core\SiteParts;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use SiteBuilder\Core\Exchange\Exchange;
use SiteBuilder\Core\Exchange\Snapshot;

/** Fresh host approval at the actual write boundary; prepared artifact metadata grants nothing. */
final class NativeGraphImportCommands
{
    public function __construct(private NativeGraphMapper $mapper, private Exchange $exchange, private NativeGraphImportScope $scope) {}

    public function import(Authenticatable $actor, Snapshot $original, SitePartGraphMap $map, Connection $target, ?NativeGraphImportPolicy $policy = null, bool $dryRun = false): array
    {
        if ($policy === null) {
            throw new AuthorizationException('A trusted destination graph import policy is required. Snapshot metadata cannot authorize application.');
        }

        return $target->transaction(function () use ($actor, $original, $map, $target, $policy, $dryRun): array {
            $mapped = $this->mapper->map($actor, $original, $map, $target, $policy);

            return $this->scope->run($actor, $original, $map, $mapped, $target,
                fn (): array => $this->exchange->import($mapped, $target, $dryRun),
                fn (): Snapshot => $this->mapper->map($actor, $original, $map, $target, $policy));
        });
    }
}
