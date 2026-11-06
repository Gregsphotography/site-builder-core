<?php

declare(strict_types=1);

namespace SiteBuilder\Core\DraftHistory;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Bounded display/grouping metadata. It cannot authorize a document change. */
final readonly class LogicalAction
{
    public function __construct(public string $kind, public string $label, public ?string $group = null, public ?string $nodeId = null, public ?string $locale = null, public array $properties = [])
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $kind) || ! mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 120 || trim($label) === '' || preg_match('/[\x00-\x1f\x7f]/', $label) || ($group !== null && ! Str::isUuid($group)) || ($nodeId !== null && strlen($nodeId) > 191) || ($locale !== null && strlen($locale) > 64) || ! array_is_list($properties) || count($properties) > 64) {
            throw ValidationException::withMessages(['history' => 'Invalid logical action metadata.']);
        }
        foreach ($properties as $property) {
            if (! is_string($property) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,127}$/D', $property)) {
                throw ValidationException::withMessages(['history' => 'Invalid typing property set.']);
            }
        }
    }

    public function toArray(): array
    {
        $properties = array_values(array_unique($this->properties));
        sort($properties, SORT_STRING);

        return ['kind' => $this->kind, 'label' => $this->label, 'group' => $this->group, 'nodeId' => $this->nodeId, 'locale' => $this->locale, 'properties' => $properties];
    }
}
