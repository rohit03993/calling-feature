<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ModelJsonClient
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public static function send(string $model, string $instructions, string $input, string $schemaName, array $schema): Response
    {
        if ((string) config('call_ai.provider') === 'gemini') {
            return self::sendGemini($instructions, $input);
        }

        $key = (string) config('call_ai.openai_key');
        $base = rtrim((string) config('call_ai.openai_base_url'), '/');

        if (str_contains($base, 'groq.com')) {
            return self::sendGroq($base, $key, $model, $instructions, $input);
        }

        return Http::withToken($key)
            ->timeout(180)
            ->post($base.'/responses', [
                'model' => $model,
                'instructions' => $instructions,
                'input' => $input,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);
    }

    private static function sendGemini(string $instructions, string $input): Response
    {
        return GeminiClient::post(
            $instructions."\n\nReturn one JSON object only.",
            [['text' => $input]],
            true,
        );
    }

    private static function sendGroq(string $base, string $key, string $model, string $instructions, string $input): Response
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions."\n\nReturn one JSON object only."],
                ['role' => 'user', 'content' => $input],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $response = Http::withToken($key)
            ->timeout(180)
            ->post($base.'/chat/completions', $payload);

        if ($response->status() !== 400) {
            return $response;
        }

        unset($payload['response_format']);

        return Http::withToken($key)
            ->timeout(180)
            ->post($base.'/chat/completions', $payload);
    }
}
