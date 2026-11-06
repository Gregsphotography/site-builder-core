<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Installation;

use Opis\JsonSchema\Validator;
use stdClass;

final class SetupArtifacts
{
    public function validate(string $kind, mixed $value): stdClass
    {
        if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 524288) {
            throw new SetupFailure('artifact-too-large', 'The setup artifact exceeds its size limit.');
        }
        if (! in_array($kind, ['setup-answers', 'setup-plan', 'setup-receipt', 'doctor'], true)) {
            throw new SetupFailure('unknown-artifact', 'Unknown setup artifact.');
        }
        $schema = json_decode(file_get_contents(__DIR__.'/../../resources/schema/'.$kind.'-v1.schema.json'), flags: JSON_THROW_ON_ERROR);
        if (! $value instanceof stdClass || (new Validator)->validate($value, $schema)->hasError()) {
            throw new SetupFailure('invalid-artifact', 'Invalid '.$kind.' artifact. Use the complete version 1 schema; unknown keys and versions are rejected.');
        }

        return $value;
    }

    public function decode(string $kind, string $json): stdClass
    {
        if (strlen($json) > 524288) {
            throw new SetupFailure('artifact-too-large', 'The setup artifact exceeds its size limit.');
        }
        try {
            $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SetupFailure('invalid-json', 'The setup artifact must be valid bounded JSON.');
        }

        return $this->validate($kind, $value);
    }
}
