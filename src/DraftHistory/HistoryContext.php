<?php

declare(strict_types=1);

namespace SiteBuilder\Core\DraftHistory;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Coordinates only: possession never grants access to checkpoints. */
final readonly class HistoryContext
{
    public function __construct(public string $id, public string $epoch, public int $sequence, public int $cursor)
    {
        if (! Str::isUuid($id) || ! Str::isUuid($epoch) || $sequence < 0 || $cursor < 0) {
            throw ValidationException::withMessages(['history' => 'Invalid history coordinates. Reload the saved draft.']);
        }
    }

    public static function fromArray(array $value): self
    {
        if (array_diff(array_keys($value), ['id', 'epoch', 'sequence', 'cursor']) !== [] || ! is_string($value['id'] ?? null) || ! is_string($value['epoch'] ?? null) || ! is_int($value['sequence'] ?? null) || ! is_int($value['cursor'] ?? null)) {
            throw ValidationException::withMessages(['history' => 'Invalid history coordinates. Reload the saved draft.']);
        }

        return new self($value['id'], $value['epoch'], $value['sequence'], $value['cursor']);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'epoch' => $this->epoch, 'sequence' => $this->sequence, 'cursor' => $this->cursor];
    }
}
