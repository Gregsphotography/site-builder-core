<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SiteBuilder\Core\Content\CanonicalJson;
use SiteBuilder\Core\Content\DocumentCodec;
use SiteBuilder\Core\Exceptions\InvalidDocument;

final class CanonicalNumbersTest extends TestCase
{
    public function test_numeric_edge_and_seeded_vectors_match_the_independent_javascript_or_refuse(): void
    {
        $codec = new CanonicalJson;
        foreach (json_decode(file_get_contents(__DIR__.'/../Fixtures/canonical-numbers.json')) as $fixture) {
            try {
                $actual = $codec->encode(json_decode($fixture->input));
                $this->assertFalse($fixture->rejected, $fixture->input);
                $this->assertSame($fixture->canonical, $actual, $fixture->input);
            } catch (InvalidArgumentException) {
                $this->assertTrue($fixture->rejected, $fixture->input);
            }
        }
    }

    public function test_numeric_collisions_and_rounding_are_refused_in_nested_serializable_values(): void
    {
        foreach ([10.0, -100.0, 1e20, 1.234567891, 1.234567891e-27, 100000000000000.1] as $number) {
            try {
                (new CanonicalJson)->digest(new class($number) implements \JsonSerializable
                {
                    public function __construct(private float $number) {}

                    public function jsonSerialize(): mixed
                    {
                        return (object) ['nested' => [$this->number]];
                    }
                });
                $this->fail('An affected number was hashed.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('exact canonical value', $exception->getMessage());
            }
        }
    }

    public function test_compatible_numbers_keep_their_recorded_bytes_and_opaque_strings(): void
    {
        $json = new CanonicalJson;
        foreach (['0' => -0.0, '1' => 1.0, '10' => 10, '0.5' => 0.5, '0.000001' => 1e-6, '1e-7' => 1e-7, '1e-27' => 1e-27, '1e+21' => 1e21, '333333333.3333333' => 333333333.3333333] as $expected => $value) {
            $this->assertSame((string) $expected, $json->encode($value));
        }
        $this->assertSame('"100000000000000000000"', $json->encode('100000000000000000000'));
    }

    public function test_historical_document_reads_preserve_affected_values_but_encoding_refuses_them(): void
    {
        $raw = '{"format":"laravel-site-builder/document","version":1,"nodes":[],"extensions":{"old":1e20}}';
        $codec = new DocumentCodec;
        $document = $codec->decode($raw);
        $this->assertSame(1e20, $document->extensions->get('old'));
        try {
            $codec->encode($document);
            $this->fail('An affected historical document was encoded for writing.');
        } catch (InvalidDocument $exception) {
            $this->assertSame('non_interoperable_number', $exception->diagnostics[0]->code);
        }
        $this->assertSame(1e20, $codec->decode($raw)->extensions->get('old'));
    }

    public function test_non_default_precision_fails_without_mutating_runtime_settings(): void
    {
        $original = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '14');
            try {
                (new CanonicalJson)->digest((object) ['value' => 1.234567891234567]);
                $this->fail('A lossy PHP serialization setting was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('serialize_precision=-1', $exception->getMessage());
                $this->assertSame('14', ini_get('serialize_precision'));
            }
        } finally {
            ini_set('serialize_precision', $original);
        }
    }
}
