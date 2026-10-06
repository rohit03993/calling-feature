<?php

namespace App\Http\Controllers;

use App\Models\ProcessedCall;
use App\Models\School;
use App\Services\AudioService;
use App\Services\CallProcessingService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CallController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $school = $this->school($request);
        $data = $request->validate([
            'call_id' => ['required', 'uuid'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'call_direction' => ['nullable', 'string', 'max:20'],
            'recorded_at' => ['nullable', 'date'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $existing = ProcessedCall::query()
            ->where('school_id', $school->id)
            ->where('public_id', $data['call_id'])
            ->first();

        if ($existing !== null) {
            return response()->json($this->payload($existing, $request));
        }

        $call = ProcessedCall::query()->create([
            'school_id' => $school->id,
            'public_id' => $data['call_id'],
            'phone_number' => PhoneNormalizer::tenDigit($data['phone_number'] ?? null),
            'call_direction' => $this->direction($data['call_direction'] ?? null),
            'recorded_at' => $data['recorded_at'] ?? now(),
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'processing_status' => 'UPLOAD_PENDING',
        ]);

        return response()->json($this->payload($call, $request), 201);
    }

    public function upload(Request $request, string $callId, AudioService $audio, CallProcessingService $processing): JsonResponse
    {
        $call = $this->findCall($request, $callId);

        if ($call->original_storage_key !== null && $call->processing_status !== 'UPLOAD_PENDING') {
            return response()->json($this->payload($call, $request));
        }

        $max = (int) config('call_ai.max_upload_kilobytes');
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:'.$max],
        ]);

        $audio->store($call, $data['audio']);
        $processing->queueFromStart($call->fresh());

        return response()->json($this->payload($call->fresh(), $request));
    }

    public function show(Request $request, string $callId): JsonResponse
    {
        return response()->json($this->payload($this->findCall($request, $callId), $request));
    }

    public function audio(Request $request, string $callId): StreamedResponse
    {
        $call = $this->findCall($request, $callId);
        $key = (string) $call->original_storage_key;

        abort_unless($key !== '' && Storage::disk('calls')->exists($key), 404);

        return Storage::disk('calls')->response($key, basename($key), [
            'Content-Type' => $call->audio_mime_type ?: 'application/octet-stream',
        ]);
    }

    public function retry(Request $request, string $callId, CallProcessingService $processing): JsonResponse
    {
        $call = $this->findCall($request, $callId);
        $processing->retry($call);

        return response()->json($this->payload($call->fresh(), $request));
    }

    private function school(Request $request): School
    {
        $school = $request->attributes->get('school');

        abort_unless($school instanceof School, 401);

        return $school;
    }

    private function findCall(Request $request, string $callId): ProcessedCall
    {
        return ProcessedCall::query()
            ->where('school_id', $this->school($request)->id)
            ->where('public_id', $callId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ProcessedCall $call, Request $request): array
    {
        return [
            'success' => true,
            'call_id' => $call->public_id,
            'upload_url' => $request->getSchemeAndHttpHost().'/api/calls/'.$call->public_id.'/audio',
            'status' => $call->processing_status,
            'summary' => $call->summary,
            'short_summary' => $call->short_summary,
            'transcript_available' => filled($call->transcript_text),
            'transcript_text' => $call->transcript_text,
            'transcript_json' => $call->transcript_json,
            'ai_analysis' => $call->ai_analysis_json,
            'duration_seconds' => $call->duration_seconds,
            'error' => $call->processing_error,
        ];
    }

    private function direction(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'outgoing', 'incoming' => $value,
            default => null,
        };
    }
}
