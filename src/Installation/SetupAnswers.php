<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Persistence\ActorIdentity;
use stdClass;

final readonly class SetupAnswers implements \JsonSerializable
{
    private stdClass $value;

    public function __construct(stdClass $value)
    {
        app(SetupArtifacts::class)->validate('setup-answers', $value);
        $this->value = json_decode(json_encode($value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        if ($this->get('features.media') && $this->get('storage.mode') === 'disabled') {
            throw new SetupFailure('storage-required', 'Selected media requires an explicit storage adapter.');
        }
        if ($this->get('storage.createLink') && $this->get('storage.mode') !== 'local-public') {
            throw new SetupFailure('local-link-required', 'A storage link requires the local-public adapter.');
        }
        if ($this->get('authorization.mode') === 'allowlist') {
            foreach ($this->get('authorization.actorKeys') as $key) {
                if (! ActorIdentity::validId($key)) {
                    throw new SetupFailure('invalid-actor-key', 'Actor keys must be bounded opaque existing identities.');
                }
            }
        }
    }

    public static function fromJson(string $json): self
    {
        return new self(app(SetupArtifacts::class)->decode('setup-answers', $json));
    }

    public function get(string $key): mixed
    {
        return data_get($this->jsonSerialize(), $key);
    }

    public function digest(): string
    {
        return app(CanonicalJson::class)->digest($this->value);
    }

    public function jsonSerialize(): stdClass
    {
        return json_decode(json_encode($this->value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    }
}
