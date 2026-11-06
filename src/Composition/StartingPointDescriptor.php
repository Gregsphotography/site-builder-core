<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use InvalidArgumentException;

final readonly class StartingPointDescriptor
{
    public function __construct(public string $id, public int $version, public string $label, public string $category = 'General', public string $description = '', public array $keywords = [], public array $variants = [], public string $sourceKind = 'component')
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}$/D', $id) || $version < 1 || $label === '' || strlen($label) > 255 || strlen($description) > 2000 || strlen($category) > 255 || ! in_array($sourceKind, ['component', 'code-template'], true)) {
            throw new InvalidArgumentException('Starting points require bounded trusted descriptors.');
        }
        foreach ([...$keywords, ...array_keys($variants), ...array_values($variants)] as $value) {
            if ((! is_string($value) && ! is_int($value)) || strlen((string) $value) > 255) {
                throw new InvalidArgumentException('Starting point keywords and variants must be bounded strings.');
            }
        }
    }

    public function variantIds(): array
    {
        return array_is_list($this->variants) ? $this->variants : array_keys($this->variants);
    }
}
