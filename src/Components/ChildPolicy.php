<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Components;

use InvalidArgumentException;

final readonly class ChildPolicy
{
    /** @param list<string> $allowedTypes */
    private function __construct(public array $allowedTypes, public int $minimum = 0, public ?int $maximum = null)
    {
        if ($minimum < 0 || ($maximum !== null && $maximum < $minimum)) {
            throw new InvalidArgumentException('Child cardinality must be nonnegative and ordered.');
        }
    }

    public static function none(): self
    {
        return new self([], 0, 0);
    }

    /** @param list<string> $types */
    public static function types(array $types, int $minimum = 0, ?int $maximum = null): self
    {
        return new self(array_values(array_unique($types)), $minimum, $maximum);
    }

    public function allows(string $type): bool
    {
        return in_array($type, $this->allowedTypes, true);
    }

    public function allowsCount(int $count): bool
    {
        return $count >= $this->minimum && ($this->maximum === null || $count <= $this->maximum);
    }
}
