<?php

namespace Tests\Unit;

use App\Support\GeminiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiFallbackTest extends TestCase
{
    public function test_a_busy_model_is_skipped_and_the_next_model_is_used(): void
    {
        config([
            'call_ai.gemini_key' => 'test-key',
            'call_ai.gemini_model' => 'gemini-3-flash-preview',
            'call_ai.gemini_fallbacks' => ['gemini-2.5-flash'],
            'call_ai.gemini_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'gemini-3-flash-preview')) {
                return Http::response([
                    'error' => ['message' => 'Quota exceeded'],
                ], 429);
            }

            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => '{"turns":[{"speaker":"staff","text":"Hello"}]}'],
                        ],
                    ],
                ]],
            ], 200);
        });

        $response = GeminiClient::post('Listen', [['text' => 'hello']], true);

        $this->assertTrue($response->successful());
        $this->assertStringContainsString('staff', (string) $response->json('candidates.0.content.parts.0.text'));
    }
}
