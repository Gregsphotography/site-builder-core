<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

/** Public metadata and private trusted execution inputs remain separate. */
final readonly class SetupPlan implements \JsonSerializable
{
    public function __construct(public array $report, public array $files, public array $migrationPaths, public array $inputs, public array $intent, public SetupAnswers $answers, public bool $runMigrations) {}

    public function jsonSerialize(): array
    {
        return $this->report;
    }
}
