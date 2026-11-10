<?php
namespace SiteBuilder\Core\Tests\Unit;
use PHPUnit\Framework\TestCase;
use SiteBuilder\Core\Content\CanonicalJson;
final class CanonicalCompatibilityTest extends TestCase
{
    public function test_licensed_backend_preserves_the_recorded_legacy_bytes(): void
    {
        $codec = new CanonicalJson;
        foreach (json_decode(file_get_contents(__DIR__.'/../Fixtures/canonical-compatibility.json')) as $row) {
            $value = json_decode($row->input);
            if ($row->legacyDifference) {
                try {
                    $codec->encode($value);
                    $this->fail('An inherited numeric collision was accepted.');
                } catch (\InvalidArgumentException) {
                    $this->assertTrue($row->legacyDifference);
                }
                continue;
            }
            $this->assertSame($row->canonical, $codec->encode($value));
            $this->assertSame($row->sha256, $codec->digest($value));
        }
    }
}
