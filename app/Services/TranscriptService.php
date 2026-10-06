<?php

namespace App\Services;

use App\Models\CallTranscriptSegment;
use App\Models\ProcessedCall;
use App\Support\HinglishTransliterator;
use App\Support\ModelJsonClient;
use App\Support\OpenAiResponseReader;
use RuntimeException;

class TranscriptService
{
    public function __construct(private TranscriptionService $transcription) {}

    public function clean(ProcessedCall $call): void
    {
        $raw = $call->transcript_json;

        if (! is_array($raw) || ! is_array($raw['segments'] ?? null) || $raw['segments'] === []) {
            throw new RuntimeException('There is no transcript to clean.');
        }

        $romanSegments = [];

        foreach ($raw['segments'] as $segment) {
            $segment['text'] = HinglishTransliterator::convert((string) ($segment['text'] ?? ''));
            $romanSegments[] = $segment;
        }

        if ((string) config('call_ai.provider') === 'gemini') {
            $cleaned = [
                'language' => 'hi',
                'segments' => array_map(
                    fn (array $segment): array => ['text' => (string) ($segment['text'] ?? '')],
                    $romanSegments,
                ),
            ];
        } else {
            $cleaned = $this->requestCleanup($romanSegments);
        }
        $sameCount = count($cleaned['segments']) === count($romanSegments);

        if (! $sameCount && count($romanSegments) === 1) {
            $joined = trim(implode(' ', array_map(
                fn (array $segment): string => (string) ($segment['text'] ?? ''),
                $cleaned['segments'],
            )));

            if ($joined !== '' && preg_match('/\p{Devanagari}/u', $joined) !== 1) {
                $cleaned['segments'] = [['text' => $joined]];
                $sameCount = true;
            }
        }

        $call->segments()->delete();

        foreach ($romanSegments as $index => $segment) {
            $modelText = $sameCount ? (string) ($cleaned['segments'][$index]['text'] ?? '') : '';

            if (preg_match('/\p{Devanagari}/u', $modelText) === 1) {
                $modelText = '';
            }

            $text = $modelText !== '' ? $modelText : (string) ($segment['text'] ?? '');
            $text = $this->polish($text);

            CallTranscriptSegment::query()->create([
                'call_id' => $call->id,
                'sequence' => $index + 1,
                'start_seconds' => $raw['segments'][$index]['start'],
                'end_seconds' => $raw['segments'][$index]['end'],
                'speaker' => $raw['segments'][$index]['speaker'],
                'text' => $text,
            ]);
        }

        $stored = [];

        foreach ($romanSegments as $index => $segment) {
            $modelText = $sameCount ? (string) ($cleaned['segments'][$index]['text'] ?? '') : '';

            if (preg_match('/\p{Devanagari}/u', $modelText) === 1) {
                $modelText = '';
            }

            $text = $modelText !== '' ? $modelText : (string) ($segment['text'] ?? '');
            $text = $this->polish($text);
            $stored[] = [
                'start' => (float) $raw['segments'][$index]['start'],
                'end' => (float) $raw['segments'][$index]['end'],
                'speaker' => (string) $raw['segments'][$index]['speaker'],
                'text' => $text,
            ];
        }

        $call->update([
            'language' => $cleaned['language'] !== '' ? $cleaned['language'] : ($call->language ?: 'auto'),
            'transcript_text' => $this->transcription->readable($stored),
            'transcript_json' => [
                'language' => $cleaned['language'] !== '' ? $cleaned['language'] : 'auto',
                'segments' => $stored,
            ],
        ]);
    }

    private function polish(string $text): string
    {
        $replacements = [
            '/\bhelo\b/i' => 'Hello',
            '/\bjee\b/i' => 'ji',
            '/\bmainne\b/i' => 'maine',
            '/\boke\b/i' => 'okay',
            '/\btaareeke\b/i' => 'tareekh',
            '/\btaim\b/i' => 'time',
            '/\bdeejiegaa\b/i' => 'dijiye',
            '/\baptober\b/i' => 'October',
            '/\baptoobar\b/i' => 'October',
            '/\bakyoobar\b/i' => 'October',
            '/\bakkyoobar\b/i' => 'October',
            '/\bdesanbar\b/i' => 'December',
            '/\bjesanbar\b/i' => 'December',
        ];

        return preg_replace(array_keys($replacements), array_values($replacements), $text) ?? $text;
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return array{language: string, segments: list<array{text: string}>}
     */
    private function requestCleanup(array $segments): array
    {
        $key = (string) config('call_ai.provider') === 'gemini'
            ? (string) config('call_ai.gemini_key')
            : (string) config('call_ai.openai_key');

        if ($key === '') {
            return [
                'language' => 'auto',
                'segments' => array_map(
                    fn (array $segment): array => ['text' => (string) ($segment['text'] ?? '')],
                    $segments,
                ),
            ];
        }

        $response = ModelJsonClient::send(
            (string) config('call_ai.cleanup_model'),
            $this->instructions(),
            json_encode(['segments' => $segments], JSON_UNESCAPED_UNICODE) ?: '{}',
            'cleaned_transcript',
            $this->schema(),
        );

        if (! $response->successful()) {
            return [
                'language' => 'auto',
                'segments' => array_map(
                    fn (array $segment): array => ['text' => (string) ($segment['text'] ?? '')],
                    $segments,
                ),
            ];
        }

        $decoded = json_decode(OpenAiResponseReader::outputText($response->json() ?? []), true);

        if (! is_array($decoded) || ! is_array($decoded['segments'] ?? null)) {
            return [
                'language' => 'auto',
                'segments' => array_map(
                    fn (array $segment): array => ['text' => (string) ($segment['text'] ?? '')],
                    $segments,
                ),
            ];
        }

        return [
            'language' => (string) ($decoded['language'] ?? 'auto'),
            'segments' => array_map(
                fn (mixed $segment): array => [
                        'text' => is_array($segment)
                            ? trim((string) ($segment['text'] ?? ''))
                            : trim((string) $segment),
                ],
                $decoded['segments'],
            ),
        ];
    }

    private function instructions(): string
    {
        return <<<'TEXT'
You clean a phone-call transcript for a CRM.

The text is a rough letter-by-letter hearing of a Hindi call.
Rewrite it as normal Hinglish, the way a person types on WhatsApp.
Fix punctuation and sentence boundaries.
Use these spellings: main, maine, mein, tha, ji, bhi, ke liye, kar denge, kar dijiye, theek, okay, time, tareekh, October, December.
These rough spellings mean October: aptoobar, akyoobar, akkyoobar.
These rough spellings mean December: desanbar, jesanbar.
karaa deejiegaa means kar dijiye.
paanch taareeke means 5 tareekh.
mainne means maine.
Do not leave broken spellings such as karaajameinge, deejiegaa, or aptoobar in the output.
Write normal readable sentences.
Do not write afternoon unless the speaker clearly said afternoon.
Keep numbers such as 20 and 10-15.
Do not turn the call into an English paragraph.
Do not invent facts.
Keep the same number of segments.
Return one JSON object. Each segment must be an object with a text field.

Example input: helo jee maim desanbar mainne bolaa thaa aptoobar mein 20 tak karaa deejiegaa
Example output text: Hello ji, main December mein bola tha. October mein 20 tak kar dijiye.
TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['language', 'segments'],
            'properties' => [
                'language' => ['type' => 'string'],
                'segments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text'],
                        'properties' => [
                            'text' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
