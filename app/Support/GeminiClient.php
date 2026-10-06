<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
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

        $base = rtrim((string) config('call_ai.gemini_base_url'), '/');
        $generation = ['temperature' => 0];

        if ($json) {
            $generation['responseMimeType'] = 'application/json';
        }

        $body = [
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
        ];

        $lastProblem = 'Gemini did not answer.';

        foreach (self::models() as $model) {
            try {
                $last = Http::withHeaders([
                    'X-goog-api-key' => $key,
                ])->connectTimeout(8)->timeout(35)->post($base.'/models/'.$model.':generateContent', $body);
            } catch (ConnectionException) {
                $lastProblem = 'Gemini did not answer in time.';

                continue;
            }

            if ($last->successful() || ! in_array($last->status(), [404, 429, 500, 503], true)) {
                return $last;
            }

            $detail = trim((string) $last->json('error.message'));
            $lastProblem = $detail !== '' ? $detail : 'Gemini refused this model.';
        }

        throw new RuntimeException($lastProblem);
    }

    /**
     * @return list<string>
     */
    private static function models(): array
    {
        return [
            'gemini-2.5-flash-lite',
            'gemini-2.5-flash',
            'gemini-3.5-flash',
        ];
    }
}
