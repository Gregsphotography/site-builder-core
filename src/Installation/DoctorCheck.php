<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

final readonly class DoctorCheck implements \JsonSerializable
{
    public function __construct(public string $id, public string $status, public string $code, public string $message, public ?string $action = null)
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $code) || ! in_array($status, ['pass', 'action', 'unavailable', 'not-applicable'], true) || strlen($message) > 2000 || strlen($action ?? '') > 2000) {
            throw new \InvalidArgumentException('Invalid setup diagnostic.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
