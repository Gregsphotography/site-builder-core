<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Content;

use InvalidArgumentException;
use Mmccook\JsonCanonicalizator\JsonCanonicalizatorFactory;
use stdClass;

/** Preserve compatible legacy bytes; refuse numbers the backend cannot encode faithfully. */
final class CanonicalJson
{
    public const string NUMERIC_POLICY = 'legacy-compatible-exact-v1';

    public function encode(mixed $value): string
    {
        if (ini_get('serialize_precision') !== '-1') {
            throw new InvalidArgumentException('Canonical JSON requires PHP serialize_precision=-1. No historical digests may be rehashed implicitly.');
        }
        $this->checkNumbers($value);
        $value = json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), flags: JSON_THROW_ON_ERROR);
        $this->checkNumbers($value);

        return $this->canonicalize($value);
    }

    public function digest(mixed $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    public function equals(mixed $left, mixed $right): bool
    {
        return $this->encode($left) === $this->encode($right);
    }

    private function checkNumbers(mixed $value): void
    {
        if ((is_int($value) && abs($value) > 9007199254740991) || (is_float($value) && ! is_finite($value))) {
            throw new InvalidArgumentException('Canonical digests require finite, interoperable JSON numbers. Preserve larger integer identifiers as strings.');
        }
        if (is_float($value) && $this->canonicalize($value) !== $this->floatToken($value)) {
            throw new InvalidArgumentException('This number cannot retain its exact canonical value. Preserve the original data and explicitly review a string or compatible numeric representation.');
        }
        if (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $child) {
                $this->checkNumbers($child);
            }
        }
    }

    private function canonicalize(mixed $value): string
    {
        return JsonCanonicalizatorFactory::getInstance()->canonicalize($value);
    }

    /** Normalize PHP's shortest round-trip decimal spelling to ECMAScript exponent thresholds. */
    private function floatToken(float $number): string
    {
        if ($number === 0.0) {
            return '0';
        }
        $token = strtolower(json_encode($number, JSON_THROW_ON_ERROR));
        if (! str_contains($token, 'e')) {
            return $token;
        }
        [$mantissa, $exponent] = explode('e', $token);
        $exponent = (int) $exponent;
        $sign = $number < 0 ? '-' : '';
        [$whole, $fraction] = array_pad(explode('.', ltrim($mantissa, '-')), 2, '');
        $digits = rtrim($whole.$fraction, '0');
        if (abs($number) >= 1e-6 && abs($number) < 1e21) {
            $point = strlen($whole) + $exponent;
            if ($point <= 0) {
                return $sign.'0.'.str_repeat('0', -$point).$digits;
            }
            if ($point >= strlen($digits)) {
                return $sign.$digits.str_repeat('0', $point - strlen($digits));
            }

            return $sign.substr($digits, 0, $point).'.'.substr($digits, $point);
        }
        $mantissa = str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa;

        return $mantissa.'e'.($exponent >= 0 ? '+' : '').$exponent;
    }
}
