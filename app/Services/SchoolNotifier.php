<?php

namespace App\Services;

use App\Models\ProcessedCall;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SchoolNotifier
{
    public function notify(ProcessedCall $call): void
    {
        $call->loadMissing('school');
        $school = $call->school;
        $url = trim((string) $school?->callback_url);

        if ($school === null || $url === '' || trim((string) $school->callback_secret) === '') {
            $call->update([
                'processing_status' => 'COMPLETED',
                'processing_error' => null,
            ]);

            return;
        }

        $payload = [
            'call_id' => $call->public_id,
            'status' => 'COMPLETED',
            'phone_number' => $call->phone_number,
            'duration_seconds' => $call->duration_seconds,
            'summary' => $call->summary,
            'short_summary' => $call->short_summary,
            'transcript_text' => $call->transcript_text,
            'transcript_json' => $call->transcript_json,
            'ai_analysis' => $call->ai_analysis_json,
            'error' => null,
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($raw === false) {
            throw new RuntimeException('The school result could not be prepared.');
        }

        $response = Http::withToken((string) $school->callback_secret)
            ->withHeaders([
                'X-Call-Ai-Signature' => hash_hmac('sha256', $raw, (string) $school->callback_secret),
            ])
            ->withBody($raw, 'application/json')
            ->timeout(30)
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException('The school CRM did not accept the result: '.$response->status());
        }

        $call->update([
            'processing_status' => 'COMPLETED',
            'processing_error' => null,
        ]);
    }
}
