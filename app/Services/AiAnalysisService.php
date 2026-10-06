<?php

namespace App\Services;

use App\Models\ProcessedCall;
use App\Support\ModelJsonClient;
use App\Support\OpenAiResponseReader;
use RuntimeException;

class AiAnalysisService
{
    /**
     * @return array<string, mixed>
     */
    public function analyze(ProcessedCall $call): array
    {
        $transcript = trim((string) $call->transcript_text);

        if ($transcript === '') {
            throw new RuntimeException('There is no transcript to analyse.');
        }

        $raw = $this->requestAnalysis($transcript);
        $analysis = $this->normalize($raw);

        $call->update([
            'summary' => $analysis['summary'],
            'short_summary' => $analysis['short_summary'],
            'ai_analysis_json' => $analysis,
            'ai_model' => (string) config('call_ai.analysis_model'),
            'processing_error' => null,
        ]);

        return $analysis;
    }

    /**
     * @return array<string, mixed>
     */
    public function analyzeText(string $transcript): array
    {
        return $this->normalize($this->requestAnalysis($transcript));
    }

    /**
     * @return array<string, mixed>
     */
    private function requestAnalysis(string $transcript): array
    {
        $key = (string) config('call_ai.provider') === 'gemini'
            ? (string) config('call_ai.gemini_key')
            : (string) config('call_ai.openai_key');

        if ($key === '') {
            throw new RuntimeException('The AI key is missing on the Call AI server.');
        }

        $response = ModelJsonClient::send(
            (string) config('call_ai.analysis_model'),
            $this->instructions(),
            $transcript,
            'call_analysis',
            $this->schema(),
        );

        if (! $response->successful()) {
            throw new RuntimeException('AI analysis failed: '.$response->status());
        }

        $decoded = json_decode(OpenAiResponseReader::outputText($response->json() ?? []), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI analysis did not return valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        $list = function (mixed $value): array {
            if (! is_array($value)) {
                return [];
            }

            return array_values(array_filter(array_map(
                fn (mixed $item): string => trim((string) $item),
                $value,
            ), fn (string $item): bool => $item !== ''));
        };

        $followUp = (bool) ($data['follow_up_required'] ?? false);
        $entities = [];

        foreach ($data['entities'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $entities[$name] = trim((string) ($row['value'] ?? ''));
        }

        return [
            'summary' => trim((string) ($data['summary'] ?? '')),
            'short_summary' => trim((string) ($data['short_summary'] ?? '')),
            'call_outcome' => trim((string) ($data['call_outcome'] ?? 'unknown')),
            'customer_intent' => trim((string) ($data['customer_intent'] ?? 'unknown')),
            'lead_status' => trim((string) ($data['lead_status'] ?? 'unknown')),
            'interest_level' => trim((string) ($data['interest_level'] ?? 'unknown')),
            'customer_requirements' => $list($data['customer_requirements'] ?? []),
            'questions_asked' => $list($data['questions_asked'] ?? []),
            'objections' => $list($data['objections'] ?? []),
            'agent_commitments' => $list($data['agent_commitments'] ?? []),
            'customer_commitments' => $list($data['customer_commitments'] ?? []),
            'important_information' => $list($data['important_information'] ?? []),
            'follow_up_required' => $followUp,
            'follow_up_reason' => $followUp ? trim((string) ($data['follow_up_reason'] ?? '')) : '',
            'suggested_follow_up_date' => $followUp ? $this->nullableString($data['suggested_follow_up_date'] ?? null) : null,
            'suggested_follow_up_time' => $followUp ? $this->nullableString($data['suggested_follow_up_time'] ?? null) : null,
            'next_action' => trim((string) ($data['next_action'] ?? '')),
            'sentiment' => trim((string) ($data['sentiment'] ?? 'unknown')),
            'entities' => $entities,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '' || strtolower($text) === 'null' || strtolower($text) === 'unknown') {
            return null;
        }

        return $text;
    }

    private function instructions(): string
    {
        return <<<'TEXT'
You are an AI call-analysis engine for a CRM system.

Your task is to analyze a customer-agent phone call transcript.

You must produce accurate, structured CRM information.

RULES:

1. Use ONLY information contained in the transcript.
2. Never invent facts.
3. Never assume customer intent if it is not supported.
4. Never invent prices, dates, names, products, promises or commitments.
5. Preserve important numbers, dates, names and amounts exactly.
6. Identify the customer and agent correctly when speaker information is available.
7. Detect the customer's actual requirement.
8. Identify questions asked by the customer.
9. Identify objections or concerns.
10. Identify promises or commitments made by the agent.
11. Identify whether follow-up is actually required.
12. Only provide a follow-up date/time when supported by the conversation.
13. Write summary and short_summary in simple English, even when the transcript is Hindi or Hinglish.
14. short_summary must be one or two sentences.
15. The summary must focus on business-relevant information.
16. Ignore irrelevant small talk.
17. Do not add information that was not discussed.
18. Preserve Hindi, English and Hinglish meaning. The summary itself stays in English.
19. Read Hinglish as Hindi mixed with English. Do not describe the transcript as garbled.
20. Return valid structured JSON matching the required schema.

If information is not present, use an empty list, an empty string, or null for a follow-up date and time.
Do not create a follow-up when the customer never agreed to one.
TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $strings = [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'summary',
                'short_summary',
                'call_outcome',
                'customer_intent',
                'lead_status',
                'interest_level',
                'customer_requirements',
                'questions_asked',
                'objections',
                'agent_commitments',
                'customer_commitments',
                'important_information',
                'follow_up_required',
                'follow_up_reason',
                'suggested_follow_up_date',
                'suggested_follow_up_time',
                'next_action',
                'sentiment',
                'entities',
            ],
            'properties' => [
                'summary' => ['type' => 'string'],
                'short_summary' => ['type' => 'string'],
                'call_outcome' => ['type' => 'string'],
                'customer_intent' => ['type' => 'string'],
                'lead_status' => ['type' => 'string'],
                'interest_level' => ['type' => 'string'],
                'customer_requirements' => $strings,
                'questions_asked' => $strings,
                'objections' => $strings,
                'agent_commitments' => $strings,
                'customer_commitments' => $strings,
                'important_information' => $strings,
                'follow_up_required' => ['type' => 'boolean'],
                'follow_up_reason' => ['type' => 'string'],
                'suggested_follow_up_date' => ['type' => ['string', 'null']],
                'suggested_follow_up_time' => ['type' => ['string', 'null']],
                'next_action' => ['type' => 'string'],
                'sentiment' => ['type' => 'string'],
                'entities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'value'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
