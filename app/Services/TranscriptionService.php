<?php

namespace App\Services;

use App\Models\CallTranscriptSegment;
use App\Models\ProcessedCall;
use App\Support\GeminiClient;
use App\Support\OpenAiResponseReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TranscriptionService
{
    public function __construct(private AudioService $audio) {}

    /**
     * @return array{language: string, segments: list<array{start: float, end: float, speaker: string, text: string}>}
     */
    public function transcribe(ProcessedCall $call): array
    {
        if ((string) config('call_ai.provider') === 'gemini') {
            return $this->storeTranscript($call, $this->geminiText($call));
        }

        $key = (string) config('call_ai.openai_key');

        if ($key === '') {
            throw new RuntimeException('The speech service key is missing on the Call AI server.');
        }

        $path = $this->audio->absolutePath($call);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The recording file could not be opened.');
        }

        $fields = [
            'model' => (string) config('call_ai.transcription_model'),
            'response_format' => (string) config('call_ai.transcription_format'),
        ];
        $language = trim((string) config('call_ai.transcription_language'));

        if ($language !== '') {
            $fields['language'] = $language;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(600)
                ->attach('file', $handle, basename($path))
                ->post(config('call_ai.openai_base_url').'/audio/transcriptions', $fields);
        } finally {
            fclose($handle);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Speech-to-text failed: '.$response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Speech-to-text returned an unreadable response.');
        }

        return $this->storeTranscript($call, trim((string) ($body['text'] ?? '')));
    }

    private function geminiText(ProcessedCall $call): string
    {
        $path = $this->recordingPath($call);
        $bytes = file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('The recording file could not be read.');
        }

        $response = GeminiClient::post(
            $this->geminiInstructions(),
            [[
                'inlineData' => [
                    'mimeType' => $this->mimeType($path),
                    'data' => base64_encode($bytes),
                ],
            ]],
            true,
        );

        if (! $response->successful()) {
            $message = trim((string) ($response->json('error.message') ?? ''));

            throw new RuntimeException('Speech-to-text failed: '.$response->status().($message !== '' ? ' '.$message : ''));
        }

        $text = OpenAiResponseReader::outputText($response->json() ?? []);
        $decoded = json_decode($text, true);
        $turns = [];

        if (is_array($decoded)) {
            $turns = is_array($decoded['turns'] ?? null) ? $decoded['turns'] : (array_is_list($decoded) ? $decoded : []);
        }

        if ($turns !== []) {
            return json_encode(['segments' => $turns], JSON_UNESCAPED_UNICODE) ?: $text;
        }

        if ($text === '' || str_starts_with(ltrim($text), '{')) {
            throw new RuntimeException('Speech-to-text returned an empty transcript.');
        }

        return $text;
    }

    private function recordingPath(ProcessedCall $call): string
    {
        $key = (string) $call->original_storage_key;

        if ($key !== '' && Storage::disk('calls')->exists($key)) {
            return Storage::disk('calls')->path($key);
        }

        return $this->audio->absolutePath($call);
    }

    private function mimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'webm' => 'audio/webm',
            'm4a', 'mp4' => 'audio/mp4',
            default => 'application/octet-stream',
        };
    }

    private function geminiInstructions(): string
    {
        return <<<'TEXT'
Listen to this phone call between a school and a parent.
Return only JSON with this shape:
{"turns":[{"speaker":"staff","text":"..."},{"speaker":"parent","text":"..."}]}

speaker is staff, parent, or unknown.
staff is the person calling from the school.
parent is the person at home.
Start a new turn every time the other person begins to speak.
Write the words in Hinglish, using English letters, the way a person types on WhatsApp.
Use normal spellings such as main, mein, tha, ji, October, December, kar dijiye, theek hai.
Keep every number exactly as spoken.
Do not invent words, names, or dates.
If a word is unclear, leave it out instead of guessing.
If you cannot tell who is speaking, use unknown.
Do not turn the call into one English paragraph.
TEXT;
    }

    /**
     * @return array{language: string, segments: list<array{start: float, end: float, speaker: string, text: string}>}
     */
    private function storeTranscript(ProcessedCall $call, string $text): array
    {
        $decoded = json_decode($text, true);
        $body = is_array($decoded) && is_array($decoded['segments'] ?? null)
            ? $decoded
            : ['text' => $text];
        $segments = $this->segmentsFromPayload($body, (int) ($call->duration_seconds ?? 0));

        $call->segments()->delete();

        foreach ($segments as $index => $segment) {
            CallTranscriptSegment::query()->create([
                'call_id' => $call->id,
                'sequence' => $index + 1,
                'start_seconds' => $segment['start'],
                'end_seconds' => $segment['end'],
                'speaker' => $segment['speaker'],
                'text' => $segment['text'],
            ]);
        }

        $readable = $this->readable($segments);

        $call->update([
            'raw_transcript' => $readable,
            'transcript_text' => $readable,
            'transcript_json' => [
                'language' => 'auto',
                'segments' => $segments,
            ],
            'transcription_model' => (string) config('call_ai.provider') === 'gemini'
                ? (string) config('call_ai.gemini_model')
                : (string) config('call_ai.transcription_model'),
            'processing_error' => null,
        ]);

        return [
            'language' => 'auto',
            'segments' => $segments,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array{start: float, end: float, speaker: string, text: string}>
     */
    public function segmentsFromPayload(array $body, int $durationSeconds): array
    {
        $rawSegments = $body['segments'] ?? null;

        if (is_array($rawSegments) && $rawSegments !== []) {
            $segments = [];
            $speakers = [];

            foreach ($rawSegments as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $text = trim((string) ($row['text'] ?? ''));

                if ($text === '') {
                    continue;
                }

                $label = strtolower(trim((string) ($row['speaker'] ?? $row['role'] ?? 'speaker')));
                $known = match ($label) {
                    'staff', 'agent', 'school', 'teacher', 'telecaller' => 'Staff',
                    'parent', 'customer', 'guardian', 'father', 'mother' => 'Parent',
                    default => null,
                };

                if ($known === null && ! isset($speakers[$label])) {
                    $speakers[$label] = count($speakers) + 1;
                }

                $segments[] = [
                    'start' => round((float) ($row['start'] ?? 0), 2),
                    'end' => round((float) ($row['end'] ?? 0), 2),
                    'speaker' => $known ?? ('Speaker '.$speakers[$label]),
                    'text' => $text,
                ];
            }

            if ($segments !== []) {
                return $segments;
            }
        }

        $text = trim((string) ($body['text'] ?? ''));

        if ($text === '') {
            throw new RuntimeException('Speech-to-text returned an empty transcript.');
        }

        return [[
            'start' => 0.0,
            'end' => (float) max($durationSeconds, 0),
            'speaker' => 'Speaker 1',
            'text' => $text,
        ]];
    }

    /**
     * @param  list<array{start: float, end: float, speaker: string, text: string}>  $segments
     */
    public function readable(array $segments): string
    {
        $blocks = [];

        foreach ($segments as $segment) {
            $blocks[] = $segment['speaker'].":\n".$segment['text'];
        }

        return implode("\n\n", $blocks);
    }
}
