<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Composition;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CopyOwner
{
    public function __construct(public string $kind, public int|string $id)
    {
        if (($kind === 'page' && is_int($id) && $id > 0 && $id <= 9007199254740991)
            || ($kind === 'site-part' && is_string($id) && Str::isUuid($id))) {
            return;
        }
        throw new InvalidArgumentException('Choose an explicit page or site-part owner identity.');
    }
}
