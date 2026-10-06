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

        $last = null;

        foreach (self::models() as $model) {
            $last = Http::withHeaders([
                'X-goog-api-key' => $key,
            ])->timeout(180)->post($base.'/models/'.$model.':generateContent', $body);

            if ($last->successful() || ! in_array($last->status(), [404, 429, 500, 503], true)) {
                return $last;
            }
        }

        if (! $last instanceof Response) {
            throw new RuntimeException('The Gemini model name is missing on the Call AI server.');
        }

        return $last;
    }

    /**
     * @return list<string>
     */
    private static function models(): array
    {
        $names = array_merge(
            [trim((string) config('call_ai.gemini_model'))],
            is_array(config('call_ai.gemini_fallbacks')) ? config('call_ai.gemini_fallbacks') : [],
        );
        $models = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name !== '' && ! in_array($name, $models, true)) {
                $models[] = $name;
            }
        }

        if ($models === []) {
            throw new RuntimeException('The Gemini model name is missing on the Call AI server.');
        }

        return $models;
    }
}
