<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Services\AiAnalysisService;
use App\Services\AudioService;
use App\Services\TranscriptService;
use App\Services\TranscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestCallController extends Controller
{
    public function transcribe(Request $request, AudioService $audio, TranscriptionService $transcription, TranscriptService $transcripts, AiAnalysisService $analysis): JsonResponse
    {
        $this->guard();

        $max = (int) config('call_ai.max_upload_kilobytes');
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:'.$max],
        ]);

        $school = $request->attributes->get('school');
        abort_unless($school instanceof School, 401);

        $call = $school->calls()->create([
            'public_id' => (string) str()->uuid(),
            'processing_status' => 'UPLOADED',
            'recorded_at' => now(),
        ]);

        $audio->store($call, $data['audio']);
        $audio->validate($call->fresh());
        $audio->preprocess($call->fresh());
        $transcription->transcribe($call->fresh());
        $transcripts->clean($call->fresh());
        $result = $analysis->analyze($call->fresh());
        $fresh = $call->fresh();

        return response()->json([
            'call_id' => $fresh->public_id,
            'transcript' => $fresh->transcript_text,
            'short_summary' => $result['short_summary'],
            'summary' => $result['summary'],
            'segments' => $fresh->transcript_json['segments'] ?? [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function analyze(Request $request, AiAnalysisService $analysis): JsonResponse
    {
        $this->guard();

        $data = $request->validate([
            'transcript' => ['required', 'string'],
        ]);

        $result = $analysis->analyzeText($data['transcript']);

        return response()->json([
            'summary' => $result['summary'],
            'intent' => $result['customer_intent'],
            'lead_status' => $result['lead_status'],
            'follow_up_required' => $result['follow_up_required'],
            'analysis' => $result,
        ]);
    }

    private function guard(): void
    {
        abort_unless((bool) config('call_ai.allow_test_routes'), 404);
    }
}
