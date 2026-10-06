<?php

namespace Tests\Unit;

use App\Services\AiAnalysisService;
use PHPUnit\Framework\TestCase;

class AiAnalysisNormalizerTest extends TestCase
{
    public function test_follow_up_date_is_cleared_when_follow_up_is_not_required(): void
    {
        $analysis = (new AiAnalysisService)->normalize([
            'summary' => 'The customer asked about Class 8.',
            'short_summary' => 'Class 8 enquiry.',
            'follow_up_required' => false,
            'follow_up_reason' => 'Call tomorrow',
            'suggested_follow_up_date' => '2026-10-07',
            'suggested_follow_up_time' => '17:30',
            'entities' => [
                ['name' => 'annual_fee', 'value' => '₹45,000'],
            ],
        ]);

        $this->assertFalse($analysis['follow_up_required']);
        $this->assertSame('', $analysis['follow_up_reason']);
        $this->assertNull($analysis['suggested_follow_up_date']);
        $this->assertNull($analysis['suggested_follow_up_time']);
        $this->assertSame('₹45,000', $analysis['entities']['annual_fee']);
    }
}
