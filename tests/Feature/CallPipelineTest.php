<?php

namespace Tests\Feature;

use App\Models\ProcessedCall;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CallPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_call_id_is_stored_once_and_pipeline_keeps_the_transcript(): void
    {
        config([
            'call_ai.skip_ffmpeg' => true,
            'call_ai.openai_key' => 'test-key',
        ]);

        $school = School::query()->create([
            'code' => 'horizon',
            'name' => 'Horizon',
            'secret' => 'school-secret',
            'callback_url' => null,
            'callback_secret' => null,
            'enabled' => true,
        ]);

        $other = School::query()->create([
            'code' => 'motion',
            'name' => 'Motion',
            'secret' => 'other-secret',
            'enabled' => true,
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/audio/transcriptions')) {
                return Http::response([
                    'text' => 'The annual fee is ₹45,000.',
                ], 200);
            }

            $body = $request->data();
            $instructions = (string) ($body['instructions'] ?? '');

            if (str_contains($instructions, 'Fix punctuation')) {
                return Http::response([
                    'output' => [[
                        'type' => 'message',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => json_encode([
                                'language' => 'en',
                                'segments' => [
                                    ['text' => 'The annual fee is ₹45,000.'],
                                ],
                            ]),
                        ]],
                    ]],
                ], 200);
            }

            return Http::response([
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'summary' => 'The customer was told the annual fee is ₹45,000.',
                            'short_summary' => 'Fee stated as ₹45,000.',
                            'call_outcome' => 'Information shared',
                            'customer_intent' => 'Fee enquiry',
                            'lead_status' => 'Interested',
                            'interest_level' => 'unknown',
                            'customer_requirements' => [],
                            'questions_asked' => [],
                            'objections' => [],
                            'agent_commitments' => [],
                            'customer_commitments' => [],
                            'important_information' => ['Annual fee ₹45,000'],
                            'follow_up_required' => false,
                            'follow_up_reason' => '',
                            'suggested_follow_up_date' => null,
                            'suggested_follow_up_time' => null,
                            'next_action' => '',
                            'sentiment' => 'neutral',
                            'entities' => [
                                ['name' => 'annual_fee', 'value' => '₹45,000'],
                            ],
                        ]),
                    ]],
                ]],
            ], 200);
        });

        $callId = (string) Str::uuid();
        $headers = [
            'Authorization' => 'Bearer school-secret',
            'X-School-Code' => 'horizon',
        ];

        $this->postJson('/api/calls', ['call_id' => $callId], $headers)->assertCreated();
        $this->postJson('/api/calls', ['call_id' => $callId], $headers)->assertOk();

        $this->assertSame(1, ProcessedCall::query()->count());

        $file = UploadedFile::fake()->createWithContent('call.m4a', str_repeat('a', 200));

        $this->withHeaders($headers)
            ->post('/api/calls/'.$callId.'/audio', ['audio' => $file])
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED');

        $call = ProcessedCall::query()->first();
        $this->assertNotNull($call);
        $this->assertStringContainsString('₹45,000', (string) $call->transcript_text);
        $this->assertStringContainsString('₹45,000', (string) $call->summary);
        $this->assertFalse($call->ai_analysis_json['follow_up_required']);
        $this->assertSame($school->id, $call->school_id);

        $this->withHeaders([
            'Authorization' => 'Bearer other-secret',
            'X-School-Code' => 'motion',
        ])->getJson('/api/calls/'.$callId)->assertNotFound();

        $this->assertTrue($other->exists());
    }

    public function test_school_from_the_env_file_is_saved_and_accepted(): void
    {
        config([
            'call_ai.school_code' => '@horizon',
            'call_ai.school_secret' => '@school-secret',
            'call_ai.school_callback_url' => 'https://horizon.example/api/call-intelligence/result',
            'call_ai.school_callback_secret' => '@callback-secret',
        ]);

        School::query()->create([
            'code' => 'local',
            'name' => 'local',
            'secret' => 'old-secret',
            'enabled' => true,
        ]);

        $callId = (string) Str::uuid();

        $this->postJson('/api/calls', ['call_id' => $callId], [
            'Authorization' => 'Bearer @school-secret',
            'X-School-Code' => '@horizon',
        ])->assertCreated();

        $school = School::query()->where('code', '@horizon')->first();
        $this->assertNotNull($school);
        $this->assertSame('@school-secret', $school->secret);
        $this->assertSame('https://horizon.example/api/call-intelligence/result', $school->callback_url);
        $this->assertSame('@callback-secret', $school->callback_secret);

        $this->postJson('/api/calls', ['call_id' => (string) Str::uuid()], [
            'Authorization' => 'Bearer old-secret',
            'X-School-Code' => '@horizon',
        ])->assertUnauthorized();
    }
}
