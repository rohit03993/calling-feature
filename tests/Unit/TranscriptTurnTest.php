<?php

namespace Tests\Unit;

use App\Services\TranscriptionService;
use Tests\TestCase;

class TranscriptTurnTest extends TestCase
{
    public function test_staff_and_parent_turns_keep_their_names(): void
    {
        $segments = app(TranscriptionService::class)->segmentsFromPayload([
            'segments' => [
                ['speaker' => 'staff', 'text' => 'Main school se bol raha hoon.'],
                ['speaker' => 'parent', 'text' => 'Haan ji, October ki fees.'],
                ['speaker' => 'unknown', 'text' => 'Theek hai.'],
            ],
        ], 0);

        $this->assertSame(['Staff', 'Parent', 'Speaker 1'], array_column($segments, 'speaker'));
        $this->assertSame('Main school se bol raha hoon.', $segments[0]['text']);
    }
}
