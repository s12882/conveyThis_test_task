<?php

namespace Tests\Feature;

use App\Services\VirusScanService;
use Tests\TestCase;

/**
 * Exercises the real clamd daemon (the `clamav` Docker service) rather than
 * a mock, using the industry-standard EICAR test string. Requires clamd to
 * be reachable, unlike the rest of the suite.
 */
class VirusScanServiceIntegrationTest extends TestCase
{
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function test_a_clean_payload_is_reported_ok(): void
    {
        $result = (new VirusScanService)->scanStream('just a harmless plain text file, nothing to see here.');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isFound());
    }

    public function test_the_eicar_test_string_is_detected_as_a_virus(): void
    {
        $result = (new VirusScanService)->scanStream(self::EICAR);

        $this->assertTrue($result->isFound());
        $this->assertStringContainsString('Eicar-Test-Signature', $result->getReason());
    }
}
