<?php

declare(strict_types=1);

namespace SiteBuilder\Core\DraftHistory;

final readonly class HistorySession
{
    public function __construct(public HistoryContext $context, public array $status) {}

    public function toArray(): array
    {
        return ['context' => $this->context->toArray(), 'status' => $this->status];
    }
}
