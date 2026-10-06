<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiClient
{
    /**
     * @param  list<array<string, mixed>>  $parts
     */
    public static function post(string $system, array $parts, bool $json = false): Response
    {
        $key = trim((string) config('call_ai.gemini_key'));

        if ($key === '') {
            throw new RuntimeException('The Gemini key is missing on the Call AI server.');
        }

        $model = trim((string) config('call_ai.gemini_model'));
        $base = rtrim((string) config('call_ai.gemini_base_url'), '/');
        $generation = ['temperature' => 0];

        if ($json) {
            $generation['responseMimeType'] = 'application/json';
        }

        return Http::withHeaders([
            'X-goog-api-key' => $key,
        ])->timeout(180)->post($base.'/models/'.$model.':generateContent', [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $system],
                ],
            ],
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => $generation,
        ]);
    }
}
