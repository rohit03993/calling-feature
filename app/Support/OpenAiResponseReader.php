<?php

namespace App\Support;

class OpenAiResponseReader
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function outputText(array $payload): string
    {
        $chunks = [];

        foreach ($payload['output'] ?? [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                if (is_array($content) && ($content['type'] ?? null) === 'output_text') {
                    $chunks[] = (string) ($content['text'] ?? '');
                }
            }
        }

        if ($chunks !== []) {
            return self::preferJson(trim(implode("\n", $chunks)));
        }

        $gemini = self::geminiText($payload);

        if ($gemini !== '') {
            return self::preferJson(self::stripFence($gemini));
        }

        $message = $payload['choices'][0]['message'] ?? null;

        if (! is_array($message)) {
            return '';
        }

        foreach (['content', 'reasoning'] as $field) {
            $text = $message[$field] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return self::preferJson(self::stripFence($text));
            }
        }

        return '';
    }

    private static function preferJson(string $text): string
    {
        $text = trim($text);

        if ($text === '' || json_decode($text, true) !== null) {
            return $text;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return $text;
        }

        $slice = substr($text, $start, $end - $start + 1);

        if (json_decode($slice, true) === null) {
            return $text;
        }

        return $slice;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function geminiText(array $payload): string
    {
        $chunks = [];

        foreach ($payload['candidates'] ?? [] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (is_array($part) && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                    $chunks[] = $part['text'];
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    private static function stripFence(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;

        return trim($text);
    }
}
